import { ApiError } from "@/lib/api";

/**
 * AC-8.5: a `400` (malformed body) and a `422` (validation) produce different, honest messages —
 * this only ever runs for a NON-422 failure (the caller checks `violationsFromError` first). AC-11.2
 * /AC-11.3: no string here ever says "forbidden"/"not allowed"/"not yours"/"permission" — a `403`
 * (which the concert endpoints aren't expected to return for ownership, D-27) reads as a session
 * problem instead of a concert-level message.
 */
export function describeConcertError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 0) {
      return "Couldn't save — check your connection and try again.";
    }
    if (error.status === 403) {
      return "Please log in again to continue.";
    }
    if (error.status === 400) {
      return error.detail ?? "That request couldn't be processed. Please check your input and try again.";
    }
    return error.detail ?? error.title;
  }
  return "Something went wrong. Please try again.";
}

/** US-11: a 404 renders the ordinary not-found state — identical for a deleted, unknown or another user's id. */
export function isNotFoundError(error: unknown): boolean {
  return error instanceof ApiError && error.status === 404;
}
