---
name: instant_setlist_refresh_ui
description: Instant setlist refresh UI (US-10 of feature/instant-setlist-refresh) — the bandId report-entry gap fixed, the refetchInterval-closure TDZ trap, refusal-body-not-error shape
metadata:
  type: project
---

Shipped US-10 of `docs/specs/2026-08-27-instant-setlist-refresh.md` on `feature/instant-setlist-refresh`
(backend for the rest of the spec was already merged into this branch by `backend-engineer`). Builds
on [[frontend_stack]], [[playlist_fast_mode_ui]].

**Backend gap found and fixed while wiring this feature** (same precedent as
[[notes_and_reviews_ui]]'s `SongOutput.id` fix): neither `PlaylistGenerationJobOutput.noSetlistCause`
(folded to ONE value across the whole lineup by `NoSetlistCauseFolder`) nor the pre-existing
`NO_SETLIST_FOR_BAND` report entry (`{band: name, cause}`) carried a band id — nothing to call
`POST /api/bands/{bandId}/setlist-refresh` with. Added `bandId` to that report entry's `params` at
both write sites, `SetlistSelectionStage::buildSkeleton()` and `SetlistChoiceApplier` (Normal mode).
Frontend reads `playlist.report` directly (`lib/setlistRefresh/fromReport.ts`,
`bandsNeedingSetlist()`) rather than the job's folded cause, so a lineup with more than one affected
band gets one action per band. Watch for PHPStan flagging `?? 0` on an array key that's already
non-nullable when adding fields like this — `bandId` is always present once you add it, so `??`
there is dead code, not defensive.

**`refetchInterval: () => ref.current` inside `useQuery` is called SYNCHRONOUSLY during
`QueryObserver` construction** — not deferred to a later render. A helper hook that needs
`query.refetch` (and so can only be called AFTER `useQuery` returns) cannot be the source of the ref
that `refetchInterval`'s closure reads, even though it looks like ordinary JS closure-over-a-later-
const would work. It crashes with `Cannot read properties of undefined (reading 'current')` at
`useQuery` call time, not later. Fix: declare the `AppStateStatus` ref via a plain `useRef` BEFORE
`useQuery`, and do the `AppState.addEventListener` effect (which needs `query.refetch`) AFTER —
exactly `usePlaylistJobPolling`'s existing order, which is why the "obvious" refactor (extract a
`useForegroundRefetch(key, refetch)` helper called after `useQuery`) doesn't work for a *second*
poller either. Ended up only extracting the truly order-independent piece, `retryAfterMs(header)`,
into `lib/api/pollingHelpers.ts`, shared by both `usePlaylistJobPolling` and the new
`useSetlistRefreshPolling` (`lib/setlistRefresh/polling.ts`) — satisfies AC-10.4's "reuse the
existing polling helper, don't invent a second mechanism" without the extraction that crashes.

**A `429` throttle refusal on `POST .../setlist-refresh` is NOT a thrown/problem+json error — it's
the SAME `BandSetlistRefreshOutput` success body, with `refusedReason`/`retryAfterAt` populated, and
the status forced to 429 by a response subscriber reading a request attribute** (D-260's
status-override mechanism, `SetlistRefreshResponseHeadersSubscriber`). `useTriggerSetlistRefresh`
(`lib/setlistRefresh/mutations.ts`) deliberately does NOT throw for status 429 — it resolves the
mutation normally with the output, same as the 202/200 cases, and the caller checks
`output.refusedReason`. The pick endpoint (`POST .../resolution`) has the same trap in reverse: it
ALWAYS returns 202 (no status-override call in `ResolveBandIdentityProcessor`), even when the
identity write succeeded but the completing setlist fetch was itself throttled — so a "successful"
202 response can still carry `refusedReason`. Its two real errors (`mbid_not_a_candidate`,
`band_already_resolved`) ARE genuine 422 `application/problem+json` errors
(`SetlistRefreshValidationException`), with the reason as `ApiError.detail` verbatim — a third shape
in the same feature, worth checking in the backend processor before assuming a status code implies
success/failure.

**Judgment call**: AC-10.2 says "the per-band `noSetlistCause` is one a refresh can plausibly help."
`NoSetlistCause::forResolutionState()` (`backend/src/Service/Playlist/Model/NoSetlistCause.php`) maps
ALL FOUR `Band::RESOLUTION_*` states 1:1 onto the four `NoSetlistCause` values, and the spec's own
US-1 Overview table gives all four a refresh path (including `no_setlist_for_show` ← `resolved`, via
the forced-live index re-fetch). So `refreshCanHelp()` (`lib/setlistRefresh/types.ts`) treats every
non-null cause as helpable — only a `null` cause (older server / empty fold) is excluded. Revisit if
a fifth cause is ever added without a matching refresh path.

Module layout (mirrors [[playlist_fast_mode_ui]]'s shape): `lib/setlistRefresh/` (`types.ts` —
hand-transcribed unions since `BandSetlistRefreshOutput`'s DTO fields are plain `?string`, not PHP
backed enums, unlike the playlist job DTO's now-resolved gap; `fromReport.ts`, `polling.ts`,
`mutations.ts`, `copy.ts`), `components/playlist/SetlistRefreshAction.tsx` (one instance per
refreshable band, rendered inline in `app/(app)/concerts/[id]/playlist.tsx`'s degraded block — no
new route/screen per D-269's Out of Scope).
