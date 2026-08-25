---
name: spec-17-normal-mode-decisions
description: Spec 17 (playlist normal mode, 2026-08-25) decisions D-188–D-209 — four suspension endpoints, client-side confirm step, user preference memory, declined report row actions
metadata:
  type: project
---

`docs/specs/2026-08-25-playlist-normal-mode.md` — **D-188 – D-209**, written 2026-08-25, status
*draft, review requested*. Full-stack (backend + frontend), one branch `feature/playlist-normal-mode`.

The consequential ones:

- **D-188 — Normal mode adds no stage, no handler, no twelfth `JobState`.** It is four API operations,
  one entity, three columns and two client view states. The mode is branched on in **exactly two
  places** (`SetlistSelectionStage`, `ReviewStage`), enforced by a static test.
- **D-190 — one suspension for all bands**, not one per band; setlist choices for a multi-band concert
  are submitted together. The four operations are sub-resources of the existing
  `PlaylistGenerationJobResource`.
- **D-194 — the confirm step is client-side.** `Confirm.dc.html`'s "step 3 of 3" is NOT a server
  state; "Build the playlist" *is* `POST …/version-choices` (T-08).
- **D-195 — an empty `CHOICE` band skips both the version step and confirm**, so Normal mode collapses
  to Fast after setlist selection. Deliberate divergence from the artboard; the price of spec 13's
  provable shared-pipeline test. Flagged as the decision most worth a second opinion (Q-5).
- **D-198 — `UserTrackPreference`**, keyed `TrackResolution`'s key **plus owner**. Never written to
  `TrackResolution` (a test asserts it), applied before banding, ignored when the remembered track is
  no longer a candidate, announced via `USED_YOUR_PREVIOUS_CHOICE`. No cross-song inference.
- **D-200 — no free-text/on-demand track search.** Spec 12's one permitted second search (D-120, the
  cover case) is scripted at match time and capped at `MAX_COVER_RESEARCH = 5` per job.
- **D-201 — YouTube arithmetic flagged for prompt 18**: ~3 cold Normal generations/day exhaust the
  10,000-unit application-wide quota. Not solved here.
- **D-204 — no raw confidence number reaches the client** on the two suspension reads; a closed label
  vocabulary (`top_pick`/`only_match`/`alternative`/`your_previous_choice`) is computed backend-side.
- **D-205 — the report's per-row actions are DECLINED**, closing spec 16's Q-1 the other way: acting on
  them post-build is playlist editing, out of scope and impossible through the frozen nine-method port.
  `ResultMostly`'s CTA stays "See what's missing" permanently.
- **D-203 closes spec 16's Q-2** — the mode sheet ships ("Fast" / "Choose it yourself"; the words
  *Fast mode* / *Normal mode* never appear in the UI).
- **D-209 — `choicesRequiredCount` / `choicesMadeCount` columns + a dashboard line**, with
  `DECISION_BUDGET = 5` as the recorded investigate-threshold. Exceeding it means re-tuning spec 12's
  `CHOICE` threshold, not redesigning this UI — stated in advance so that outcome reads as success.

**Why:** prompt 17's stated make-or-break risk is the decision count, and its stated failure mode is
Normal mode forking into a parallel pipeline. Every structural decision above is aimed at one of those
two.

**How to apply:** the next spec continues at **D-210**. Open questions Q-1 (paste a setlist.fm URL —
recommended *no*, it is a budget decision worth its own backlog item) through Q-5 await approval.
See [[spec-house-style]], [[spec-16-fast-mode-ui-decisions]], [[spec-12-song-matching-decisions]].
