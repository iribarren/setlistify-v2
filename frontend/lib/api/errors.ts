/**
 * AC-7.3/AC-7.4: a network failure, a timeout and a non-problem-JSON body all produce this same
 * typed shape, so a caller never has to distinguish transport failure from HTTP failure.
 */
export class ApiError extends Error {
  /** HTTP status, or `0` for a failure that never reached an HTTP response (network, timeout). */
  readonly status: number;
  readonly title: string;
  readonly detail?: string;
  readonly type?: string;
  /**
   * The raw parsed response body, when one exists. Typed `unknown` rather than the generated
   * response schema because this class is transport-generic — it does not know which operation
   * failed. Callers that need the typed shape (e.g. `useHealth`'s 503 handling) narrow this value
   * against a generated type themselves; see `frontend/lib/api/useHealth.ts`.
   */
  readonly body?: unknown;

  constructor(params: { status: number; title: string; detail?: string; type?: string; body?: unknown }) {
    super(params.title);
    this.name = "ApiError";
    this.status = params.status;
    this.title = params.title;
    this.detail = params.detail;
    this.type = params.type;
    this.body = params.body;
  }
}

/** The RFC 7807 shape the backend's `Error` schema and `application/problem+json` responses use. */
interface ProblemDetails {
  title?: string | null;
  detail?: string | null;
  status?: number | null;
  type?: string;
  instance?: string | null;
}

/** Narrows an unknown decoded JSON body to RFC 7807's shape without asserting `any` (AC-1.5). */
function isProblemDetails(value: unknown): value is ProblemDetails {
  return typeof value === "object" && value !== null;
}

/**
 * Builds an `ApiError` from a non-2xx `Response` and the body `openapi-fetch` already parsed for
 * it. Takes the pre-parsed body (rather than re-reading `response.json()`) because a `Response`'s
 * body stream can only be consumed once, and `openapi-fetch` has already consumed it by the time
 * this runs (AC-7.3). Handles: RFC 7807 `application/problem+json` bodies, a JSON body that isn't
 * problem+json shaped (falls back to a generic title), and no parseable body at all (AC-7.4).
 */
export function toApiError(response: Response, parsedBody: unknown): ApiError {
  if (isProblemDetails(parsedBody)) {
    return new ApiError({
      status: response.status,
      title: parsedBody.title ?? response.statusText ?? "Request failed",
      detail: parsedBody.detail ?? undefined,
      type: parsedBody.type,
      body: parsedBody,
    });
  }

  return new ApiError({
    status: response.status,
    title: response.statusText || "Request failed",
    body: parsedBody,
  });
}

/** Network failure or `AbortController` timeout — never reached an HTTP response (AC-7.5). */
export function networkApiError(cause: unknown, timedOut: boolean): ApiError {
  return new ApiError({
    status: 0,
    title: timedOut ? "Request timed out" : "Network request failed",
    detail: cause instanceof Error ? cause.message : undefined,
  });
}
