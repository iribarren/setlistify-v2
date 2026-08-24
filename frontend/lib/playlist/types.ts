import type { components } from "@/api";

/**
 * D-177: every request/response shape in this feature is an alias of a generated schema type.
 * Nothing here is hand-declared.
 */
export type PlaylistGenerationJobOutput =
  components["schemas"]["PlaylistGenerationJob.PlaylistGenerationJobOutput.jsonld"];
export type StartGenerationInput = components["schemas"]["PlaylistGenerationJob.StartGenerationInput"];
export type PlaylistOutput = components["schemas"]["Playlist.PlaylistOutput.jsonld"];
export type PlaylistTrackOutput = components["schemas"]["PlaylistTrackOutput.jsonld"];
export type ReportEntryOutput = components["schemas"]["ReportEntryOutput.jsonld"];
export type ProviderConfigOutput = components["schemas"]["ProviderConfig.ProviderConfigOutput.jsonld"];
export type SourceSetlistOutput = components["schemas"]["SourceSetlistOutput.jsonld"];

/**
 * D-167/AC-5.4/D-177: every literal union below is an alias of the corresponding generated schema
 * field — `frontend/api/schema.d.ts` now carries a real `enum` for `state` / `blockedReason` /
 * `failureReason` / `resultKind` / `outcome` / `reasonCode` / `code` (the backend's output DTOs were
 * fixed in `bugfix/playlist-report-enum-typing` to declare the PHP backed enum types instead of plain
 * `?string` — see `backend/src/ApiResource/Playlist/*.php`), so `openapi-typescript` narrows them
 * itself. Nothing here is hand-transcribed any more.
 *
 * `exhaustiveArray()` still gives each full enumeration a compile-time-checked runtime array: if the
 * backend adds/removes/renames a case, the generated union changes and the corresponding array below
 * fails to typecheck until it's updated — the same guarantee D-167 asks of `reportCopy.ts`'s
 * `Record`, extended to every enum in this module. The `as*` narrowers still runtime-guard the one
 * boundary where wire data enters, so a value the *client* doesn't know about yet (client older than
 * server) degrades to a safe fallback instead of a type lie.
 */
function exhaustiveArray<T extends string>() {
  return function <A extends readonly T[]>(array: [T] extends [A[number]] ? A : never): A {
    return array;
  };
}

export type JobState = NonNullable<PlaylistGenerationJobOutput["state"]>;

export const JOB_STATES = exhaustiveArray<JobState>()([
  "queued",
  "resolving_setlist",
  "awaiting_setlist_choice",
  "matching",
  "awaiting_version_choice",
  "building",
  "blocked",
  "completed",
  "failed",
  "expired",
  "cancelled",
] as const);

export const ACTIVE_JOB_STATES: readonly JobState[] = [
  "queued",
  "resolving_setlist",
  "matching",
  "building",
];
export const TERMINAL_JOB_STATES: readonly JobState[] = ["completed", "failed", "expired", "cancelled"];

export type BlockedReason = NonNullable<PlaylistGenerationJobOutput["blockedReason"]>;

export const BLOCKED_REASONS = exhaustiveArray<BlockedReason>()([
  "setlistfm_budget",
  "provider_quota",
  "provider_rate_limit",
  "needs_reauth",
  "provider_disabled",
  "upstream_unavailable",
] as const);

export type FailureReason = NonNullable<PlaylistGenerationJobOutput["failureReason"]>;

export const FAILURE_REASONS = exhaustiveArray<FailureReason>()([
  "creation_indeterminate",
  "unknown_provider",
  "block_cycles_exhausted",
] as const);

export type ResultKind = NonNullable<PlaylistGenerationJobOutput["resultKind"]>;

export const RESULT_KINDS = exhaustiveArray<ResultKind>()([
  "complete",
  "partial",
  "no_source_material",
  "no_tracks_matched",
] as const);

export type NoSetlistCause = NonNullable<PlaylistGenerationJobOutput["noSetlistCause"]>;

export const NO_SETLIST_CAUSES = exhaustiveArray<NoSetlistCause>()([
  "band_unknown",
  "band_ambiguous",
  "no_setlist_for_show",
  "identity_unavailable",
] as const);

export type TrackOutcome = NonNullable<PlaylistTrackOutput["outcome"]>;

export const TRACK_OUTCOMES = exhaustiveArray<TrackOutcome>()([
  "pending",
  "matched",
  "matched_low_confidence",
  "skipped",
  "not_found",
  "region_restricted",
] as const);

/**
 * The same backed enum drives both `PlaylistTrackOutput.reasonCode` (per-track, nullable) and
 * `ReportEntryOutput.code` (job-level report rows, never null) — `ReportCode` aliases the former with
 * `null` stripped, and a `satisfies`-style check isn't needed for the latter since it's a strict
 * subset (in fact the same set) of this union.
 */
export type ReportCode = NonNullable<PlaylistTrackOutput["reasonCode"]>;

export const REPORT_CODES = exhaustiveArray<ReportCode>()([
  "COVER_OF",
  "LIVE_VERSION_ONLY",
  "LOW_CONFIDENCE_MATCH",
  "TAPE_NOT_PERFORMED",
  "PERFORMANCE_ARTIFACT",
  "TRACK_NOT_IN_CATALOG",
  "TRACK_VANISHED",
  "NOT_AVAILABLE_IN_REGION",
  "NO_SETLIST_FOR_BAND",
  "SETLIST_MAY_BE_STALE",
  "SELECTED_FROM",
  "BANDS_OMITTED_FOR_LENGTH",
  "SETLIST_TRUNCATED",
  "RESUMED_MID_INSERTION",
  "FALLBACK_LONGEST_SETLIST",
] as const);

function makeNarrower<T extends string>(values: readonly T[]) {
  const set = new Set<string>(values);
  return (value: string | null | undefined): T | null => (value && set.has(value) ? (value as T) : null);
}

export const asJobState = makeNarrower(JOB_STATES);
export const asBlockedReason = makeNarrower(BLOCKED_REASONS);
export const asFailureReason = makeNarrower(FAILURE_REASONS);
export const asResultKind = makeNarrower(RESULT_KINDS);
export const asTrackOutcome = makeNarrower(TRACK_OUTCOMES);
export const asReportCode = makeNarrower(REPORT_CODES);
export const asNoSetlistCause = makeNarrower(NO_SETLIST_CAUSES);
