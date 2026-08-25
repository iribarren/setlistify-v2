---
name: project_playlist_normal_mode_start_generation_gap
description: StartGenerationProcessor hardcoded JobMode::Fast with no resumeFromJobId path; userChoices was never written anywhere despite being documented as "kept through expiry, for pre-filling a new job". Fixed on feature/playlist-normal-mode.
metadata:
  type: project
---

Continuation of [[project_playlist_normal_mode_staleness]]. Spec 17
(docs/specs/2026-08-25-playlist-normal-mode.md, AC-1.1/AC-4.3/AC-4.4) shipped `JobMode::Normal`,
`UserTrackPreference`, `StalenessReconciler` and the four sub-resource operations, but the ONLY
entry point that creates a `PlaylistGenerationJob` — `POST /api/playlist-generation-jobs`
(`StartGenerationInput` + `StartGenerationProcessor`) — still hardcoded `JobMode::Fast` and had no
`resumeFromJobId` field. Every Normal-mode test constructed the entity directly, so this was never
caught. Fixed by adding `mode`/`resumeFromJobId` to `StartGenerationInput` (mode optional,
`Assert\Choice` against `JobMode::Fast/Normal`'s values, default fast) and wiring
`StartGenerationProcessor` to resolve them via `PlaylistGenerationJobLocator` (same 404-not-403
lookup the four suspension operations use) + a 422 when the referenced job isn't `JobState::Expired`.

**A second, deeper gap: `userChoices` was never written anywhere**, despite the entity's own
docblock calling it "Kept through expiry, for pre-filling a new job" — neither
`SetlistChoiceApplier` nor `VersionChoiceApplier` ever called `setUserChoices()`, and
`JobStateMachine::expire()` didn't drop `candidateSetlists`/`pendingChoices` either (AC-4.1 was
therefore also unimplemented, silently). Fixed:
- `SetlistChoiceApplier::apply()` now writes `userChoices['setlistChoices'] = [{bandId,
  setlistfmId}, ...]` right before nulling `candidateSetlists`.
- `JobStateMachine::expire()` now nulls `candidateSetlists`/`pendingChoices` (keeps `userChoices`).
- `StartGenerationProcessor` copies the resumed-from job's `userChoices` onto the new job at
  creation, plus sets `resumedFromJob`.
- `SetlistSelectionStage::resumedSetlistChoices()` reads `$job->getResumedFromJob()?->getUserChoices()`
  and overrides `recommendedSetlistfmId`/`recommendedReason` (new `SelectionReason::ResumedFromPreviousChoice`
  case) when the previously-chosen setlistfmId is still among the new job's candidates for that band.
  This does NOT add a second `JobMode::Normal` branch in this file — it only reads
  `$job->getResumedFromJob()`, so AC-7.2's two-branch static scan still passes.

**Scoped down deliberately: version-choice pre-fill (AC-4.3's other half) needed NO new code.**
`MatchingStage` already consults `UserTrackPreference` unconditionally before CHOICE-banding
(confirmed by reading the file — this part of spec 17 WAS actually wired), and
`VersionChoiceApplier::recordPreference()` already writes one on every accepted choice. Since
`UserTrackPreference` is keyed by `(owner, provider, algorithmVersion, normalizedArtist,
normalizedTitle)` — not by job — a resumed job's MatchingStage run picks up the old job's accepted
version choices for free, and AC-5.4 ("stale preference ignored, song becomes a decision again")
already covers AC-4.4's "candidate gone → surfaces as a decision again" for versions. Declined
choices (`USER_DECLINED`) are NOT preference-recorded and so are NOT pre-filled in a resumed job —
they simply surface as a fresh decision, which satisfies AC-4.4's spirit but is worth knowing if a
future spec wants declines pre-filled too (would need its own field on `userChoices`).

**Test container cache trap recurred**: after adding a new constructor arg to
`StartGenerationProcessor`, `bin/console cache:clear --env=test` was NOT enough — the compiled DI
container in `var/cache/test/Container*` kept the stale factory and threw a `TypeError` on the new
argument until `rm -rf var/cache/test` (see [[project_backoffice_provider_configuration]]'s
debug:false stale-cache entry — same root cause, worse here because `cache:clear` alone didn't fix
it this time).
