import createClient, { type Middleware } from "openapi-fetch";

import type { paths } from "@/api";

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
});

/**
 * AC-7.6 — the one documented place request headers are attached. This is the extension point
 * prompt 04 (auth) uses to add `Authorization: Bearer <token>`. It exists now and attaches
 * nothing yet. AC-7.8: whatever prompt 04 adds here must not log the token on any platform.
 */
const authHeaderSeam: Middleware = {
  onRequest() {
    // Intentionally empty — prompt 04 attaches `Authorization` here.
    return undefined;
  },
};
apiClient.use(authHeaderSeam);

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
    if (response.ok && data !== undefined) {
      return data;
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
