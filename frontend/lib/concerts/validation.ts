import type { ConcertFormValues } from "./mapping";

// D-31/D-36: mirrors the server's documented bounds so obvious mistakes are caught before a round
// trip — but this is advisory only. The server's response is always authoritative (AC-8.2); nothing
// here ever suppresses or overrides a violation that comes back from the API.
export const MIN_BANDS = 1;
export const MAX_BANDS = 30;
export const BAND_NAME_MAX = 120;
export const MIN_DATE = "1900-01-01";

/** `now + 5 years`, as a `YYYY-MM-DD` string (D-31, AC-9.2). */
export function maxDate(reference: Date = new Date()): string {
  const max = new Date(reference);
  max.setFullYear(max.getFullYear() + 5);
  return max.toISOString().slice(0, 10);
}

export interface FormFieldErrors {
  date?: string;
  bands?: Record<number, string>;
}

/** AC-8.1: client-side validation mirroring the server's documented bounds. */
export function validateFormValues(values: ConcertFormValues, reference: Date = new Date()): FormFieldErrors {
  const errors: FormFieldErrors = {};

  if (!values.date) {
    errors.date = "Enter a date.";
  } else if (values.date < MIN_DATE || values.date > maxDate(reference)) {
    errors.date = "Enter a date between 1900 and five years from now.";
  }

  const nonEmptyBands = values.bands.filter((band) => band.name.trim().length > 0);
  if (nonEmptyBands.length < MIN_BANDS) {
    errors.bands = { 0: "Add at least one band." };
  } else if (values.bands.length > MAX_BANDS) {
    errors.bands = { ...(errors.bands ?? {}), [MAX_BANDS]: `Up to ${MAX_BANDS} bands are accepted.` };
  } else {
    const bandErrors: Record<number, string> = {};
    values.bands.forEach((band, index) => {
      const trimmed = band.name.trim();
      if (trimmed.length === 0) {
        return;
      }
      if (trimmed.length > BAND_NAME_MAX) {
        bandErrors[index] = `Band names are at most ${BAND_NAME_MAX} characters.`;
      }
    });
    if (Object.keys(bandErrors).length > 0) {
      errors.bands = bandErrors;
    }
  }

  return errors;
}

export function hasFormErrors(errors: FormFieldErrors): boolean {
  return Boolean(errors.date || (errors.bands && Object.keys(errors.bands).length > 0));
}
