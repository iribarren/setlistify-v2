import type { components } from "@/api";

/**
 * docs/specs/2026-08-27-instant-setlist-refresh.md, AC-10.7: every request/response shape here is
 * an alias of the generated schema. Nothing is hand-declared.
 */
export type BandSetlistRefreshOutput =
  components["schemas"]["BandSetlistRefresh.BandSetlistRefreshOutput.jsonld"];
export type ResolveBandIdentityInput =
  components["schemas"]["BandSetlistRefresh.ResolveBandIdentityInput"];
export type BandSearchCandidateOutput = components["schemas"]["BandSearchCandidateOutput.jsonld"];

/**
 * `BandSetlistRefreshOutput`'s `state`/`bandResolutionState*`/`refusedReason` fields are declared
 * `?string` on the backend DTO (`backend/src/ApiResource/Setlist/BandSetlistRefreshOutput.php`) —
 * plain string constants (`Band::RESOLUTION_*`), not PHP backed enums like
 * `PlaylistGenerationJobOutput`'s fields — so `openapi-typescript` cannot narrow them the way
 * `lib/playlist/types.ts` documents for that DTO (see the `[[playlist_fast_mode_ui]]` memory entry
 * for that resolved gap). The unions below are hand-transcribed from the backend phpdoc
 * (`@var 'queued'|'running'|...`) instead, same fallback the rest of this codebase uses for a
 * non-enum wire field — `exhaustiveArray()` still gives each one a compile-time-checked runtime
 * array, and `asXxx` narrowers still guard the one boundary where wire data enters.
 */
function exhaustiveArray<T extends string>() {
  return function <A extends readonly T[]>(array: A): A {
    return array;
  };
}

export type RefreshState = "queued" | "running" | "succeeded" | "failed";
export const REFRESH_STATES = exhaustiveArray<RefreshState>()(["queued", "running", "succeeded", "failed"] as const);

export type BandResolutionState = "unresolved" | "resolved" | "ambiguous" | "no_presence";
export const BAND_RESOLUTION_STATES = exhaustiveArray<BandResolutionState>()([
  "unresolved",
  "resolved",
  "ambiguous",
  "no_presence",
] as const);

export type RefusedReason =
  | "cooldown_active"
  | "daily_limit_reached"
  | "budget_reserved"
  | "budget_exhausted"
  | "rate_limited"
  | "upstream_unavailable";

export const REFUSED_REASONS = exhaustiveArray<RefusedReason>()([
  "cooldown_active",
  "daily_limit_reached",
  "budget_reserved",
  "budget_exhausted",
  "rate_limited",
  "upstream_unavailable",
] as const);

/** AC-4.7: the pick's two refusals — distinct from `RefusedReason`, never `429`, always `422`. */
export type PickRefusalReason = "mbid_not_a_candidate" | "band_already_resolved";

function makeNarrower<T extends string>(values: readonly T[]) {
  const set = new Set<string>(values);
  return (value: string | null | undefined): T | null => (value && set.has(value) ? (value as T) : null);
}

export const asRefreshState = makeNarrower(REFRESH_STATES);
export const asBandResolutionState = makeNarrower(BAND_RESOLUTION_STATES);
export const asRefusedReason = makeNarrower(REFUSED_REASONS);

/**
 * AC-10.2: which `NoSetlistCause` values a refresh can plausibly help. `NoSetlistCause` maps 1:1
 * onto `Band::RESOLUTION_*` (`backend/src/Service/Playlist/Model/NoSetlistCause.php`), and every one
 * of the four resolution states has a refresh path per the spec's table (US-1 Overview): `unresolved`
 * and `no_presence` re-attempt the search, `ambiguous` re-attempts and reports candidates, and a
 * `resolved` band with no setlist for *this* show (`no_setlist_for_show`) gets a forced-live index
 * re-fetch. So — judgment call, since the spec doesn't enumerate an "unhelpable" cause — every
 * non-null `noSetlistCause` qualifies; only a `null` cause (an older server, or a fold that found
 * nothing) does not.
 */
export function refreshCanHelp(cause: string | null | undefined): boolean {
  return cause != null;
}
