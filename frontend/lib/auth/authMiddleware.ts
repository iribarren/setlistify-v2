import type { Middleware } from "openapi-fetch";

import { clientPlatformHeader } from "./platform";
import { performRefresh } from "./refreshCoordinator";
import { getAccessToken } from "./tokenStore";

/**
 * Requests that must never trigger the refresh-on-401 interceptor below:
 * - `/api/token/refresh` itself — the endpoint that would recurse into itself on a genuine 401
 *   (an unknown/expired/reused refresh token).
 * - `/api/login`, `/api/users`, `/api/password-reset/*`, `/api/email-verification/confirm` — none
 *   of these are ever called with a bearer token; a 401 from one of them is a normal auth failure
 *   the calling screen renders directly, not a session that needs refreshing.
 */
const REFRESH_EXEMPT_PATHS = [
  "/api/token/refresh",
  "/api/login",
  "/api/logout",
  "/api/users",
  "/api/password-reset/request",
  "/api/password-reset/confirm",
  "/api/email-verification/confirm",
];

function isExemptPath(pathname: string): boolean {
  return REFRESH_EXEMPT_PATHS.some((path) => pathname.endsWith(path));
}

/**
 * A `Request`'s body can only be read once, and `fetch()` has already read it by the time
 * `onResponse` runs — so retrying means resending a clone taken BEFORE the request was sent, not
 * reconstructing one from the (already-consumed) `request` object `onResponse` receives. Keyed by
 * the middleware call's own `id` (stable across `onRequest`/`onResponse` for one logical request,
 * per openapi-fetch), cleared on every response so this never grows unbounded.
 */
const pendingClones = new Map<string, Request>();

/**
 * AC-2.6/AC-7.6 (frontend skeleton): the one place `Authorization` and `X-Client-Platform` are
 * attached, and the one place a stray `application/json` default (openapi-fetch's default
 * serializer) is corrected to the `application/ld+json` API Platform actually accepts — verified
 * manually against the running backend (`application/json` 415s; `application/ld+json` doesn't).
 * No screen or hook sets any of these headers itself.
 */
export const authHeaderMiddleware: Middleware = {
  onRequest({ request, id }) {
    request.headers.set("X-Client-Platform", clientPlatformHeader());

    const token = getAccessToken();
    if (token) {
      request.headers.set("Authorization", `Bearer ${token}`);
    }

    if (request.headers.get("Content-Type") === "application/json") {
      request.headers.set("Content-Type", "application/ld+json");
    }

    // Snapshot before the body is consumed by the actual send, for refreshRetryMiddleware.
    pendingClones.set(id, request.clone());

    return undefined;
  },
};

/**
 * AC-4.5/AC-4.7: on a 401 from a non-exempt endpoint, joins (or starts) the single-flight refresh,
 * then retries the original request exactly once with the new access token. If refresh fails, the
 * original 401 is returned unchanged — the caller's normal `ApiError` handling takes it from there
 * — and `refreshCoordinator` has already emitted `sessionExpired` so `SessionProvider` routes to
 * login without this module touching navigation directly.
 */
export const refreshRetryMiddleware: Middleware = {
  async onResponse({ request, response, id }) {
    const clone = pendingClones.get(id);
    pendingClones.delete(id);

    if (response.status !== 401) {
      return undefined;
    }

    const url = new URL(request.url);
    if (isExemptPath(url.pathname) || !clone) {
      return undefined;
    }

    try {
      const tokens = await performRefresh();
      clone.headers.set("Authorization", `Bearer ${tokens.accessToken}`);
      return await fetch(clone);
    } catch {
      return undefined;
    }
  },
};
