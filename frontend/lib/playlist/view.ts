import { alternativeProviderFor, type ConnectedAccountLike, type ProviderCandidate } from "./providerChoice";
import {
  asBlockedReason,
  asFailureReason,
  asJobState,
  asResultKind,
  type PlaylistGenerationJobOutput,
  type PlaylistOutput,
  type ProviderConfigOutput,
} from "./types";

/** D-166: the copy decision, not a quality one — the only new number this spec introduces. */
export const MOSTLY_MATCHED_FLOOR = 0.5;

export type PlaylistViewKind =
  | "idle"
  | "progress"
  // Normal mode (docs/specs/2026-08-25-playlist-normal-mode.md, D-202): the two suspension points.
  | "choose_setlist"
  | "choose_versions"
  | "expired"
  | "result_full"
  | "result_mostly"
  | "result_barely"
  | "result_nothing"
  | "degraded_band_unknown"
  | "degraded_no_songs"
  | "blocked_budget"
  | "blocked_quota"
  | "blocked_reauth"
  | "blocked_disabled"
  | "blocked_upstream"
  | "failed_indeterminate"
  | "failed_generic";

export interface PlaylistView {
  kind: PlaylistViewKind;
  job: PlaylistGenerationJobOutput | null;
  playlist: PlaylistOutput | null;
  /** Only populated for `blocked_disabled` (D-175). */
  alternativeProvider: ProviderCandidate | null;
}

function view(
  kind: PlaylistViewKind,
  job: PlaylistGenerationJobOutput | null,
  playlist: PlaylistOutput | null,
  alternativeProvider: ProviderCandidate | null = null,
): PlaylistView {
  return { kind, job, playlist, alternativeProvider };
}

/**
 * Counters are already on the job/playlist — never recomputed from the track array (AC-4.2).
 * `matchRate = hits / (songsTotal - skipped)`, mirroring `TrackOutcome::countsInMatchRate()`.
 */
function matchRateOf(job: PlaylistGenerationJobOutput, playlist: PlaylistOutput | null): number {
  if (typeof playlist?.matchRate === "number") {
    return playlist.matchRate;
  }
  const hits = (job.matchedCount ?? 0) + (job.lowConfidenceCount ?? 0);
  const denominator = (job.songsTotal ?? 0) - (job.skippedCount ?? 0);
  return denominator > 0 ? hits / denominator : 0;
}

function resultView(job: PlaylistGenerationJobOutput, playlist: PlaylistOutput | null): PlaylistView {
  const resultKind = asResultKind(job.resultKind);
  switch (resultKind) {
    case "complete":
      return view("result_full", job, playlist);
    case "partial": {
      const rate = matchRateOf(job, playlist);
      return view(rate >= MOSTLY_MATCHED_FLOOR ? "result_mostly" : "result_barely", job, playlist);
    }
    case "no_tracks_matched":
      return view("result_nothing", job, playlist);
    case "no_source_material": {
      // D-187/AC-1.5: `noSetlistCause` (folded server-side, D-184) is the one signal this branch
      // switches on. `no_setlist_for_show` means the band IS known on setlist.fm — `DegradedNoSongs`'s
      // "Known on setlist.fm" badge and "check back in a few days" promise are both truthful there.
      // `band_unknown`/`band_ambiguous`/`identity_unavailable` route to `degraded_band_unknown`. A
      // `null` cause (an older server, or a fold that found nothing) is NOT lumped in with those three
      // — it keeps today's `degraded_no_songs` default, so an older server can't break a newer client.
      const isKnownUnknownCause = job.noSetlistCause != null && job.noSetlistCause !== "no_setlist_for_show";
      return view(isKnownUnknownCause ? "degraded_band_unknown" : "degraded_no_songs", job, playlist);
    }
    default:
      // A resultKind the client doesn't recognise yet, on an otherwise-completed job — treat as the
      // safest completed variant rather than crash: nothing matched is always an honest floor.
      return view("result_nothing", job, playlist);
  }
}

function blockedView(
  job: PlaylistGenerationJobOutput,
  playlist: PlaylistOutput | null,
  providers: ProviderConfigOutput[] | undefined,
  accounts: ConnectedAccountLike[] | undefined,
): PlaylistView {
  const reason = asBlockedReason(job.blockedReason);
  switch (reason) {
    case "setlistfm_budget":
      return view("blocked_budget", job, playlist);
    case "provider_quota":
    case "provider_rate_limit":
      return view("blocked_quota", job, playlist);
    case "needs_reauth":
      return view("blocked_reauth", job, playlist);
    case "provider_disabled":
      return view(
        "blocked_disabled",
        job,
        playlist,
        alternativeProviderFor(job.provider, providers, accounts),
      );
    case "upstream_unavailable":
      return view("blocked_upstream", job, playlist);
    default:
      // An unrecognised blocked reason is still, definitionally, not an error (D-appropriate
      // fallback): render it with the quota layout's "we'll keep trying, nothing to redo" framing
      // rather than inventing an error state for a wait the pipeline itself considers recoverable.
      return view("blocked_upstream", job, playlist);
  }
}

function failedView(job: PlaylistGenerationJobOutput, playlist: PlaylistOutput | null): PlaylistView {
  const reason = asFailureReason(job.failureReason);
  if (reason === "creation_indeterminate") {
    return view("failed_indeterminate", job, playlist);
  }
  // unknown_provider, block_cycles_exhausted, or anything unrecognised — all offer the same "Try
  // again" recovery (D-170).
  return view("failed_generic", job, playlist);
}

/**
 * D-166/D-170: the ONE place the server state maps to a screen. Pure, and the only source of the
 * mapping — `playlist.tsx` renders whatever this returns and nothing else decides.
 *
 * `job` is the current/most-recent job for the concert (or `null` if none exists yet — AC-3.2's
 * cold-start case). `playlist` is the generated playlist, once one exists (`GET /api/playlists`) —
 * used only for `matchRate`/report display, never to re-derive a decision the job already made.
 */
export function derivePlaylistView(
  job: PlaylistGenerationJobOutput | null,
  playlist: PlaylistOutput | null,
  providers?: ProviderConfigOutput[],
  accounts?: ConnectedAccountLike[],
): PlaylistView {
  if (!job) {
    return view("idle", null, playlist);
  }

  const state = asJobState(job.state);
  switch (state) {
    case "queued":
    case "resolving_setlist":
    case "matching":
    case "building":
      return view("progress", job, playlist);
    // D-202: Normal mode's two suspension points get their own screens now that they're reachable.
    case "awaiting_setlist_choice":
      return view("choose_setlist", job, playlist);
    case "awaiting_version_choice":
      return view("choose_versions", job, playlist);
    case "blocked":
      return blockedView(job, playlist, providers, accounts);
    case "completed":
      return resultView(job, playlist);
    case "failed":
      return failedView(job, playlist);
    // AC-4.2: `expired` gets its own info-family screen (a divergence from spec 16's original
    // expired -> idle mapping, made deliberately by this spec — a Normal-mode session that lapsed is
    // not "nothing happened", it's "something happened and stopped", which deserves an explanation).
    case "expired":
      return view("expired", job, playlist);
    case "cancelled":
      return view("idle", job, playlist);
    default:
      // An unrecognised state on an otherwise-present job: treat as still-in-progress rather than
      // erroring — the safest assumption when the server has state the client doesn't know yet.
      return view("progress", job, playlist);
  }
}
