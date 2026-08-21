import type {
  ConcertInput,
  ConcertOutput,
  ConcertPatchInput,
  MoneyData,
} from "./types";

// D-38: money and dates are converted in exactly one place — this file. No component does
// arithmetic on a price and no component parses a date string.

/** One row of the lineup as edited in the Add/Edit form. `key` is a stable id for list rendering. */
export interface BandFormValue {
  key: string;
  /** Free-text band name (prompt 09 replaces this with search). */
  name: string;
  /** Present only when this row started life as an already-known `Band` (edit flow). */
  bandId?: number;
}

export interface ConcertFormValues {
  /** ISO-8601 calendar date, `YYYY-MM-DD`, or `""` while unset. */
  date: string;
  /** IANA identifier (D-35). */
  timezone: string;
  bands: BandFormValue[];
  venueName: string;
  venueCity: string;
  venueCountryCode: string;
  /** Decimal amount as typed, e.g. `"12.50"` or `"12,50"` (AC-3.7). Empty string = no price. */
  priceAmount: string;
  priceCurrency: string;
  /** Local wall-clock `HH:MM` (AC-3.8). Empty string = unset. */
  doorsTime: string;
  startTime: string;
  note: string;
}

let keySeq = 0;
function nextKey(): string {
  keySeq += 1;
  return `band-${Date.now()}-${keySeq}`;
}

export function emptyBandFormValue(): BandFormValue {
  return { key: nextKey(), name: "" };
}

/** D-35: the device's IANA timezone, used as the default on create. */
export function defaultTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  } catch {
    return "UTC";
  }
}

export function createEmptyFormValues(): ConcertFormValues {
  return {
    date: "",
    timezone: defaultTimezone(),
    bands: [emptyBandFormValue()],
    venueName: "",
    venueCity: "",
    venueCountryCode: "",
    priceAmount: "",
    priceCurrency: "USD",
    doorsTime: "",
    startTime: "",
    note: "",
  };
}

/** AC-6.1: edit opens the same form as add, pre-filled from the concert. */
export function concertOutputToFormValues(concert: ConcertOutput): ConcertFormValues {
  const lineup = [...(concert.lineup ?? [])].sort(
    (a, b) => (a.billingOrder ?? 0) - (b.billingOrder ?? 0),
  );

  return {
    date: concert.date ?? "",
    timezone: concert.timezone ?? defaultTimezone(),
    bands:
      lineup.length > 0
        ? lineup.map((entry) => ({
            key: nextKey(),
            name: entry.band?.name ?? "",
            bandId: entry.band?.id,
          }))
        : [emptyBandFormValue()],
    venueName: concert.venue?.name ?? "",
    venueCity: concert.venue?.city ?? "",
    venueCountryCode: concert.venue?.countryCode ?? "",
    priceAmount:
      concert.ticketPrice?.amount != null ? formatMinorUnitsAsDecimalInput(concert.ticketPrice.amount) : "",
    priceCurrency: concert.ticketPrice?.currency ?? "USD",
    doorsTime: concert.doorsTime ?? "",
    startTime: concert.startTime ?? "",
    note: concert.note ?? "",
  };
}

/**
 * AC-3.7: `12,50` and `12.50` both yield `1250`. Returns `null` for an empty/unparsable input so
 * the caller can distinguish "no price" from "zero price".
 */
export function parseMoneyInput(raw: string): number | null {
  const trimmed = raw.trim();
  if (!trimmed) {
    return null;
  }
  const normalized = trimmed.replace(",", ".");
  const value = Number(normalized);
  if (!Number.isFinite(value)) {
    return null;
  }
  return Math.round(value * 100);
}

export function formatMinorUnitsAsDecimalInput(amountMinorUnits: number): string {
  return (amountMinorUnits / 100).toFixed(2);
}

/** AC-5.4: rendered with `Intl.NumberFormat` from minor units + ISO 4217 code. */
export function formatMoney(money: MoneyData | null | undefined, locale?: string): string | null {
  if (!money || money.amount == null || !money.currency) {
    return null;
  }
  try {
    return new Intl.NumberFormat(locale, { style: "currency", currency: money.currency }).format(
      money.amount / 100,
    );
  } catch {
    return `${(money.amount / 100).toFixed(2)} ${money.currency}`;
  }
}

/**
 * AC-5.5/D-35: formats a concert's own calendar date in its own timezone, never converted toward
 * the viewer's zone. Anchoring at local noon on that calendar day (rather than midnight UTC) means
 * the `timeZone` option can never push the formatted result to the previous or next day, no matter
 * which zone is asked to render it — the date component itself is what's authoritative here, not a
 * specific instant.
 */
export function formatConcertDate(
  date: string,
  timezone: string,
  locale?: string,
  options: Intl.DateTimeFormatOptions = { dateStyle: "medium" },
): string {
  const anchor = new Date(`${date}T12:00:00Z`);
  try {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone: timezone }).format(anchor);
  } catch {
    return date;
  }
}

/** AC-9.4: builds the `Concert.ConcertInput` create payload from the form model. */
export function formValuesToConcertInput(values: ConcertFormValues): ConcertInput {
  const priceAmount = parseMoneyInput(values.priceAmount);

  return {
    date: values.date || null,
    timezone: values.timezone || null,
    lineup: values.bands
      .map((band) => band.name.trim())
      .filter((name) => name.length > 0)
      .map((name) => ({ name })),
    venue: hasVenue(values)
      ? {
          name: values.venueName.trim() || null,
          city: values.venueCity.trim() || null,
          countryCode: values.venueCountryCode.trim() || null,
        }
      : null,
    ticketPrice:
      priceAmount != null ? { amount: priceAmount, currency: values.priceCurrency.trim() || "USD" } : null,
    doorsTime: values.doorsTime.trim() || null,
    startTime: values.startTime.trim() || null,
    note: values.note.trim() || null,
  };
}

/**
 * AC-6.2/AC-6.4: builds the `Concert.ConcertPatchInput.jsonMergePatch` payload. The form always
 * submits its full current state, so an optional field the user cleared is sent as an explicit
 * `null` — which JSON merge-patch semantics treat as "remove this field" — rather than being
 * omitted (which would leave the old server value in place).
 */
export function formValuesToPatchInput(values: ConcertFormValues): ConcertPatchInput {
  return formValuesToConcertInput(values);
}

function hasVenue(values: ConcertFormValues): boolean {
  return Boolean(values.venueName.trim() || values.venueCity.trim() || values.venueCountryCode.trim());
}
