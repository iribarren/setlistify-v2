import type { components } from "@/api";
import { ApiError } from "@/lib/api";

type ConstraintViolation = components["schemas"]["ConstraintViolation"];

/**
 * AC-9.2: a 429 renders as a specific, human message — not the generic RFC 7807 title/detail —
 * everything else falls back to the server's own `detail` (already human-written by the backend
 * per US-9's enumeration-resistant, but not content-free, error bodies).
 */
export function describeAuthError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      return "Too many attempts. Please wait a minute and try again.";
    }
    if (error.status === 0) {
      return error.detail ?? "Couldn't reach the server. Check your connection and try again.";
    }
    return error.detail ?? error.title;
  }
  return "Something went wrong. Please try again.";
}

function isConstraintViolationBody(value: unknown): value is ConstraintViolation {
  return typeof value === "object" && value !== null && "violations" in value;
}

/**
 * AC-1.4: maps a 422's per-field violations (e.g. the password policy) onto the matching
 * `TextInput`'s `errorMessage`, so the same server-stated policy renders inline rather than only
 * as a generic banner.
 */
export function fieldViolations(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError) || error.status !== 422 || !isConstraintViolationBody(error.body)) {
    return {};
  }
  const result: Record<string, string> = {};
  for (const violation of error.body.violations ?? []) {
    if (violation.propertyPath) {
      result[violation.propertyPath] = violation.message;
    }
  }
  return result;
}
