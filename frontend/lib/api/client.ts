import createClient from "openapi-fetch";

import type { paths } from "@/api";
import { authHeaderMiddleware, refreshRetryMiddleware } from "@/lib/auth/authMiddleware";

import { networkApiError, toApiError, type ApiError } from "./errors";

const DEFAULT_TIMEOUT_MS = 10_000;

/**
 * AC-7.2: the base URL is read once, at module load, from `EXPO_PUBLIC_API_URL`. A missing value
 * fails loudly and immediately — never a silent relative-URL request.
 */
function requireApiBaseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) {
    throw new Error(
      "EXPO_PUBLIC_API_URL is not set. Copy frontend/.env.example to frontend/.env.local " +
        "(see docs/env-vars.md, \"Frontend (Expo)\") before starting the app.",
    );
  }
  return url;
}

/**
 * D-11: `openapi-fetch` bound to the generated `paths` type is the transport — an unknown path or
 * a wrong body shape is a compile error for free. Setlistify-specific behaviour (base URL, auth
 * header seam) lives in the middleware below; this is the ONE client every hook in this directory
 * uses. No component or screen calls `fetch` directly (AC-7.7, frontend/README.md).
 */
export const apiClient = createClient<paths>({
  baseUrl: requireApiBaseUrl(),
  // openapi-fetch captures its `fetch` implementation once, at client-creation time. Indirecting
  // through a wrapper (rather than letting it default to `globalThis.fetch` directly) means tests
  // that stub `global.fetch` (D-14, AC-10.5) after this module has already loaded still take
  // effect — with no behavioral difference in the app, which never reassigns `global.fetch`.
  fetch: (input) => globalThis.fetch(input),
  // D-18: the refresh-token cookie is httpOnly and `SameSite=Strict` — the browser only attaches
  // it on a credentialed request. Harmless on native, which has no cookie jar to speak of and
  // authenticates the refresh flow via the request body instead (AC-4.6).
  credentials: "include",
});

// AC-2.6/AC-7.6 (frontend skeleton): `Authorization`/`X-Client-Platform` attachment and the
// single-flight refresh-on-401 retry both live in `lib/auth/authMiddleware.ts` — this is the one
// place they're wired onto the client every request goes through. No screen or hook touches a
// token or sets these headers itself (AC-8.4).
apiClient.use(authHeaderMiddleware, refreshRetryMiddleware);

export interface RequestOptions {
  /** Overrides the default 10s timeout (AC-7.5). */
  timeoutMs?: number;
  signal?: AbortSignal;
}

/**
 * Runs a request through an `AbortController`-based timeout (AC-7.5) and normalizes both
 * transport failures and non-2xx HTTP responses into the same `ApiError` (AC-7.4). Every query
 * hook in this directory goes through this function rather than reading `{ data, error }` from
 * `openapi-fetch` directly, so the error-shape guarantee holds in exactly one place.
 */
export async function unwrap<T>(
  run: (signal: AbortSignal) => Promise<{ data?: T; error?: unknown; response: Response }>,
  options: RequestOptions = {},
): Promise<T> {
  const controller = new AbortController();
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

  // Let an externally-supplied signal (e.g. a query's own cancellation) abort too.
  const externalSignal = options.signal;
  const onExternalAbort = () => controller.abort();
  externalSignal?.addEventListener("abort", onExternalAbort);

  try {
    const { data, error, response } = await run(controller.signal);
    if (response.ok) {
      // A 204 (logout, password-reset/confirm, email-verification/confirm — US-5/6/7) has no
      // body, so `data` is `undefined` even on success; only a non-2xx is a failure.
      return data as T;
    }
    // `error` is the body openapi-fetch already parsed for a non-2xx response — reuse it rather
    // than reading `response.json()` again, which would throw ("body already consumed").
    throw toApiError(response, error);
  } catch (cause) {
    if (cause instanceof Error && cause.name === "AbortError") {
      throw networkApiError(cause, true);
    }
    if (isApiError(cause)) {
      throw cause;
    }
    throw networkApiError(cause, false);
  } finally {
    clearTimeout(timeoutId);
    externalSignal?.removeEventListener("abort", onExternalAbort);
  }
}

function isApiError(value: unknown): value is ApiError {
  return value instanceof Error && value.name === "ApiError";
}
