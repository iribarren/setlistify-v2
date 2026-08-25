---
name: project_playlist_normal_mode_staleness
description: StalenessReconciler orchestration wiring gotchas (fingerprint-bug, ordinal flush ordering, D-59 immutability), fixed on bugfix work over feature/playlist-normal-mode.
metadata:
  type: project
---

Continuation of [[project_playlist_result_state_gaps]]/[[project_playlist_fast_mode_backend]], on
`feature/playlist-normal-mode` (docs/specs/2026-08-25-playlist-normal-mode.md,
docs/specs/2026-08-23-spike-playlist-pipeline.md §6, AC-8.1/AC-8.2/AC-8.3). A prior pass had written
`StalenessReconciler`'s per-row methods (fingerprint mismatch, algorithmVersion bump, purged setlist)
as unit-tested pure functions but never actually called them from `MatchingStage`'s resume path —
only the two generically-covered rows (provider disabled/token expired via pre-flight; concert
deleted via FK cascade) were reachable. Fixed by adding
`StalenessReconciler::reconcileResume(PlaylistGenerationJob, Playlist, \DateTimeImmutable)` as the
single entry point, called at the very top of `MatchingStage::run()` on every attempt (idempotent —
a no-op on a fresh first pass, since nothing has had time to drift yet).

**`PlaylistSkeletonBuilder` had a real bug feeding this**: it stored
`'fingerprint' => $setlist->getSetlistfmId()` into `selectedSetlists[].fingerprint` instead of
`StalenessReconciler::fingerprint($setlist)` (the sha256-of-ordered-titles content hash the
reconciler actually compares against). The fingerprint mismatch row could never have fired correctly
until this was fixed — the stored value never changes when a setlist's content does, since
`setlistfmId` is the show's identity, not its content. Any future field added to `selectedSetlists`
that's meant to detect drift needs the SAME care: verify the stored value is actually a function of
the thing being watched, not an adjacent identity field that happens to be nearby in the same array
literal.

**D-59 ("a cached Setlist's Songs are immutable — `SetlistNormalizer::hydrateOne()` returns an
already-known `setlistfmId` row as-is, never re-parses it") means `Song` has NO title setter, by
design.** There is currently no real application code path that mutates an existing Song's title in
place — "the setlist was corrected on setlist.fm" (spec 13 §6 row 1) is a real detection mechanism
with no known production writer yet. Tests for this row must simulate the corrected state via a raw
`UPDATE songs SET title = ...` through the DBAL connection (never add a `Song::setTitle()` just to
make a test convenient), followed by `$em->clear()` before re-fetching — otherwise Doctrine's
identity map keeps serving the stale in-memory title.

**A resume-time fallback that removes `PlaylistTrack` rows and inserts replacements reusing freed
ordinals must flush the removal BEFORE inserting** — Doctrine orders one flush's INSERTs before its
DELETEs, so removing old rows via `Playlist::removeTrack()` (orphanRemoval) and then immediately
building new rows at the same ordinal via `PlaylistSkeletonBuilder::appendBandTracks()` in the SAME
flush throws `UniqueConstraintViolationException` on `uniq_playlist_track_ordinal` even though the
in-memory collection already looks empty. Fix: an explicit `$this->entityManager->flush()` between
the removal loop and the rebuild call.

**Deleting a `Setlist` row via `$em->remove()` cascades its `Song` rows and nulls dependent
`PlaylistTrack.sourceSong` purely at the DB level** (`ON DELETE CASCADE` / `ON DELETE SET NULL` FK
constraints, no ORM-level `cascade: ['remove']` needed) — but Doctrine's identity map doesn't know
this happened until `$em->clear()` and a fresh fetch. Tests simulating a cache purge need that
clear+refetch too, same as the title-correction case above.

**Testing pattern that worked well**: drive `PreflightStage` → `SetlistSelectionStage` →
`JobStateMachine::enterMatching()` → a direct `MatchingStage::run()` call by hand (same shape as
`PlaylistPipelineIdempotentRetryTest::buildMatchedPlaylistWithoutCreating()`) to get a job stopped
mid-flight (`matching`, not `completed`) without going through full pipeline completion twice; apply
the staleness mutation; then hand the job to ONE real `PlaylistPipeline::run()` call for the "resume"
— this reaches a genuine terminal `Completed` state (proving AC-8.2's "never `failed`") while keeping
the mutation window isolated. Calling `pipeline()->run()` twice on the same job was avoided
deliberately — `ReportStage` appends to `reportSummary` rather than replacing it, so a second full run
would double every report entry; that guard is enforced one layer up (`BuildPlaylistHandler`) in
production and isn't safe to bypass casually in a test either.

See [[project_playlist_fast_mode_backend]] for the `TestDoubleStreamingProvider` scripting API
(`searchTrack()`'s default: one fixed candidate `double-track-1` at confidence 0.9 for ANY song
title unless scripted otherwise — useful for asserting "only N searches happened" via
`getSearchTrackCallCount()` deltas rather than exact track ids) and the phpstan/phpunit
`-d memory_limit=-1` requirement in this container.

**AC-9.2 (the backoffice "Playlist generation" panel gaining median/p95 `choicesRequiredCount`,
abandonment rate, zero-taps share for `mode = normal` jobs) is still fully unimplemented** —
`PlaylistDashboardMetrics.php` has no normal-mode/choice-count logic at all as of this fix. Confirmed
deliberately deferred, not a regression from this bugfix; it's a real feature slice (median/p95
aggregation + an abandonment-rate calc across suspension states), not a trivial addition.
