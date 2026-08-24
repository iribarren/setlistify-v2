import { asReportCode, type ReportCode } from "./types";

export type ReasonParams = Record<string, string | null> | null | undefined;

function param(params: ReasonParams, key: string): string {
  const value = params?.[key];
  return value && value.length > 0 ? value : "the original artist";
}

/**
 * D-167: total over `ReportCode` — an alias of the generated `reasonCode`/`code` union (see
 * `./types.ts`) — so adding a case here is required to add a case to the enum, and vice versa;
 * TypeScript refuses to compile this file if either drifts from the other (AC-5.4). Every sentence
 * names a cause a non-technical person recognises, per `Report.dc.html`'s register — never a
 * mechanism, never the code itself (AC-5.3).
 */
const REPORT_COPY: Record<ReportCode, (params: ReasonParams) => string> = {
  // Per-song
  COVER_OF: (params) => `This is a cover — the original is by ${param(params, "artist")}.`,
  LIVE_VERSION_ONLY: () => "Only a live recording is available — the studio version isn't on the provider.",
  LOW_CONFIDENCE_MATCH: () => "We matched this one, but we're not fully sure it's the right recording.",
  TAPE_NOT_PERFORMED: () => "This was a recording played over the PA, not performed live — excluded.",
  PERFORMANCE_ARTIFACT: () => "This was an instrumental interlude or crowd moment, not a song.",
  TRACK_NOT_IN_CATALOG: () => "Not on the provider's catalog — likely unreleased or never streamed.",
  TRACK_VANISHED: () => "This track was removed from the provider's catalog after we first found it.",
  NOT_AVAILABLE_IN_REGION: () => "This track isn't available in your region on the provider.",

  // Job-level
  NO_SETLIST_FOR_BAND: (params) => `No setlist could be built for ${param(params, "band")}.`,
  SETLIST_MAY_BE_STALE: () => "This setlist may not be fully up to date yet.",
  SELECTED_FROM: (params) => {
    const date = params?.["date"];
    const venue = params?.["venue"];
    const detail = [date, venue].filter(Boolean).join(" — ");
    return detail ? `Built from the setlist for ${detail}.` : "Built from the show's most recent substantial setlist.";
  },
  BANDS_OMITTED_FOR_LENGTH: (params) => {
    const bands = params?.["bands"];
    return bands ? `${bands} left out to keep the playlist a reasonable length.` : "Some bands were left out to keep the playlist a reasonable length.";
  },
  SETLIST_TRUNCATED: () => "This setlist was trimmed to keep the playlist a reasonable length.",
  RESUMED_MID_INSERTION: () => "This generation resumed partway through — nothing already added was redone.",
  FALLBACK_LONGEST_SETLIST: () => "No single \"most recent\" setlist stood out, so we used the longest one available.",
};

/** AC-5.3: an unrecognised runtime code never renders as a code — a specific, honest fallback instead. */
const UNKNOWN_CODE_FALLBACK = "There's a specific reason for this one, but we don't have a description for it yet.";

/**
 * The one place English exists for a report code (D-167). `code` is the raw wire value — narrowed
 * against the generated union here, at the boundary, so a code the backend added ahead of the client
 * degrades to the honest fallback and is logged in development, never displayed as the code itself
 * (AC-5.3).
 */
export function describeReportCode(code: string | null | undefined, params: ReasonParams): string {
  const known = asReportCode(code ?? undefined);
  if (!known) {
    if (__DEV__ && code) {
      // AC-5.3: logged in development only, never rendered as the code itself.
      console.warn(`[playlist] Unrecognised report code from the server: ${code}`);
    }
    return UNKNOWN_CODE_FALLBACK;
  }
  return REPORT_COPY[known](params);
}
