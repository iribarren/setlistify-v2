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

/**
 * KNOWN SPEC DEVIATION (flagged in the implementation report, not hacked around silently):
 *
 * D-167/AC-5.4 ask for `reportCopy.ts` to be "typed against the generated `reasonCode` union, so an
 * unmapped code is a compile error." Spec 14 §1 says every one of these columns is a PHP backed enum
 * (`enumType: JobState` etc.) — but the *output DTOs* that API Platform reflects into the OpenAPI
 * document (`PlaylistTrackOutput::$reasonCode`, `PlaylistGenerationJobOutput::$state`, etc. — see
 * `backend/src/ApiResource/Playlist/*.php`) declare those properties as plain `?string`, not the enum
 * type. `openapi-typescript` therefore generates `string` for `state` / `blockedReason` /
 * `failureReason` / `resultKind` / `outcome` / `reasonCode` / `code` — no literal union survives into
 * `frontend/api/schema.d.ts` for the client to alias.
 *
 * Rather than silently widening the exhaustiveness check to `Record<string, ...>` (which would defeat
 * D-167's entire point — a new backend code would then be a silent runtime gap again, not a compile
 * error), the literal unions below are transcribed verbatim from the backend's own enum
 * declarations (`backend/src/Service/Playlist/Model/*.php`) and used as the compile-time contract.
 * They are runtime-narrowed at the one boundary where wire data enters (`asJobState`, `asReportCode`,
 * etc.) so an unrecognised runtime value degrades to a safe fallback instead of a type lie.
 *
 * The real fix belongs on the backend: type the output DTO properties with the PHP enum itself (or
 * add an explicit OpenAPI `enum:` annotation) so these unions come from `schema.d.ts` like everything
 * else in this file. Recorded here so it isn't rediscovered from scratch.
 */
export const JOB_STATES = [
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
] as const;
export type JobState = (typeof JOB_STATES)[number];

export const ACTIVE_JOB_STATES: readonly JobState[] = [
  "queued",
  "resolving_setlist",
  "matching",
  "building",
];
export const TERMINAL_JOB_STATES: readonly JobState[] = ["completed", "failed", "expired", "cancelled"];

export const BLOCKED_REASONS = [
  "setlistfm_budget",
  "provider_quota",
  "provider_rate_limit",
  "needs_reauth",
  "provider_disabled",
  "upstream_unavailable",
] as const;
export type BlockedReason = (typeof BLOCKED_REASONS)[number];

export const FAILURE_REASONS = ["creation_indeterminate", "unknown_provider", "block_cycles_exhausted"] as const;
export type FailureReason = (typeof FAILURE_REASONS)[number];

export const RESULT_KINDS = ["complete", "partial", "no_source_material", "no_tracks_matched"] as const;
export type ResultKind = (typeof RESULT_KINDS)[number];

export const TRACK_OUTCOMES = [
  "pending",
  "matched",
  "matched_low_confidence",
  "skipped",
  "not_found",
  "region_restricted",
] as const;
export type TrackOutcome = (typeof TRACK_OUTCOMES)[number];

export const REPORT_CODES = [
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
] as const;
export type ReportCode = (typeof REPORT_CODES)[number];

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
