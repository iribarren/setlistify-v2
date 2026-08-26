import { ApiError } from "@/lib/api";

import type { ConstraintViolation, Violation } from "./types";

/**
 * D-36/AC-8.3–AC-8.4: an RFC 7807 `422` violation, mapped from its `propertyPath` onto the concert
 * form's fields. `bands` is keyed by lineup index (`lineup[2].name` → `bands[2]`). Anything whose
 * path doesn't map to a visible field lands in `formErrors` — nothing is silently dropped, and no
 * code path renders `detail`/`title`/a raw JSON body directly (AC-8.4).
 */
export interface ViolationFieldErrors {
  date?: string;
  timezone?: string;
  venueName?: string;
  venueCity?: string;
  venueCountryCode?: string;
  priceAmount?: string;
  priceCurrency?: string;
  doorsTime?: string;
  startTime?: string;
  bands: Record<number, string>;
  formErrors: string[];
}

const LINEUP_PATH = /^lineup\[(\d+)]\.(name|bandId)$/;

function emptyViolationFieldErrors(): ViolationFieldErrors {
  return { bands: {}, formErrors: [] };
}

export function mapViolationsToFields(violations: Violation[]): ViolationFieldErrors {
  const result = emptyViolationFieldErrors();

  for (const violation of violations) {
    const path = violation.propertyPath;
    const message = violation.message;

    const lineupMatch = LINEUP_PATH.exec(path);
    if (lineupMatch) {
      const index = Number(lineupMatch[1]);
      result.bands[index] = message;
      continue;
    }

    switch (path) {
      case "date":
        result.date = message;
        break;
      case "timezone":
        result.timezone = message;
        break;
      case "venue.name":
        result.venueName = message;
        break;
      case "venue.city":
        result.venueCity = message;
        break;
      case "venue.countryCode":
        result.venueCountryCode = message;
        break;
      case "ticketPrice.amount":
        result.priceAmount = message;
        break;
      case "ticketPrice.currency":
        result.priceCurrency = message;
        break;
      case "doorsTime":
        result.doorsTime = message;
        break;
      case "startTime":
        result.startTime = message;
        break;
      default:
        // AC-8.4: an unrecognised path still surfaces, just at the form level rather than next to
        // a specific input.
        result.formErrors.push(message);
    }
  }

  return result;
}

function isConstraintViolationShaped(value: unknown): value is ConstraintViolation {
  return typeof value === "object" && value !== null && "violations" in value;
}

/**
 * AC-8.5: a `400` (malformed body) and a `422` (validation) are handled differently — this only
 * ever returns something for a genuine `422` with a parseable violations array.
 */
export function violationsFromError(error: unknown): Violation[] | null {
  if (!(error instanceof ApiError) || error.status !== 422) {
    return null;
  }
  if (!isConstraintViolationShaped(error.body)) {
    return null;
  }
  return error.body.violations ?? [];
}
