# FEATURE — Playlist generation: Normal mode (the interactive path)

| | |
|---|---|
| **Spec ID** | `2026-08-25-playlist-normal-mode` |
| **Backlog prompt** | `docs/prompts/17-playlist-normal-mode.md` |
| **Command** | `/feature playlist-normal-mode` |
| **Primary agent** | `backend-engineer` + `frontend-engineer` |
| **Type** | **FEATURE — implementation follows this document directly.** One branch `feature/playlist-normal-mode`, one PR spanning both sub-projects (`CLAUDE.md` — *one feature, one spec, one branch*) |
| **Depends on** | `12` song matching (approved) · `13` playlist pipeline (approved 2026-08-23) · `14` fast-mode backend (approved 2026-08-23, merged) · `15` playlist flow design (canvas delivered) · `16` fast-mode UI (approved 2026-08-24, merged) · the result-state-gaps fix (merged) |
| **Implemented by** | *(this is the implementation)* — extended by `18` YouTube adapter, `19` playback |
| **Decisions** | **D-188** – **D-209** |
| **Status** | **Approved 2026-08-25** |

---

## Overview

### What this feature is

Fast mode guesses. Normal mode asks — twice, and never more than twice.

Spec 13 designed one pipeline with two suspension points already in it. Spec 14 built that pipeline
and shipped it with both guards present but only ever evaluating false, because `mode` was always
`fast`. Spec 16 built the client that drives it and deliberately left two things un-wired: the mode
chooser sheet (its Q-2) and the report's per-row actions (its D-171).

**This spec sets `mode = normal` and makes the two suspensions real.** It adds no stage, no second
handler, no parallel pipeline and no twelfth job state. What it adds is four API operations that
read and write the two suspension payloads spec 13 already specified (`candidateSetlists`,
`pendingChoices`), two client view states inside the route spec 16 already built, and one small piece
of genuinely new behaviour the prompt asks for: remembering a user's version choices so the second
generation for the same band asks less than the first.

The user-visible outcome, in one sentence: **from a tracked concert, a user who wants control picks
which night's setlist to use, confirms only the handful of songs Setlistify is genuinely unsure
about, and gets the playlist they meant — resuming exactly where they left off if they walk away for
three days.**

The quality bar, restated from the prompt because this is precisely where it is lost: **a 25-song
setlist must not become 25 questions.** Spec 12's `CHOICE` band (`0.55 ≤ conf < 0.80`) is the
mechanism that turns 25 into roughly 3, and spec 15's `VersionSelect.dc.html` is the screen that
proves it can be reviewed in one scroll with zero mandatory taps. This spec instruments that claim
(D-209) rather than asserting it, because if the median decision count is not small, the correct
outcome of this work is a threshold change in spec 12 — not a nicer list.

### This spec re-decides nothing that specs 12, 13, 14, 15 and 16 already decided

- Every job state, transition, TTL, staleness rule, `blockedReason`, `failureReason`, `resultKind`
  and report code comes from **spec 13** (`docs/specs/2026-08-23-spike-playlist-pipeline.md`,
  D-125–D-144) **verbatim**. In particular §6 *Suspend and resume (Normal mode)* is the design this
  spec implements; it is not revisited.
- Every existing endpoint, response shape, `Retry-After` cadence, `ETag` rule and ownership gate
  comes from **spec 14** (`docs/specs/2026-08-23-playlist-fast-mode-backend.md`, D-145–D-160).
- The `AUTO_ACCEPT` / `CHOICE` / `REJECT` banding (`0.80` / `0.55`), the confidence formula, the
  one-search-per-song rule (D-120) and its single named exception come from **spec 12**
  (`docs/specs/2026-08-22-spike-song-matching.md`, D-106–D-124).
- Every screen, headline, tone decision and colour family comes from **spec 15's canvas**
  (`docs/design/canvas/playlist-flow/`) — `SetlistSelect.dc.html`, `VersionSelect.dc.html`,
  `Confirm.dc.html`, `Resume.dc.html`, plus `Main.dc.html`'s mode sheet. **The artboards, not this
  document, are the visual source of truth.**
- The client file layout, polling implementation and state→screen mapping come from **spec 16**
  (`docs/specs/2026-08-24-playlist-fast-mode-ui.md`, D-161–D-181).

This document's decisions, **D-188–D-209**, are only about what none of those decided: the four
suspension endpoints, the shape of a choice submission, preference memory, the confirm step's
ownership, the decision-count instrumentation, and the two things spec 16 parked for this prompt.

Where implementation reveals one of those documents was wrong, it is corrected **in that document,
in this branch**, not diverged from silently.

### Load-bearing rules this spec does not reverse

| Rule (`CLAUDE.md`) | How this design honours it |
|---|---|
| **The streaming port is the only way to reach a provider** | No new port method. Version candidates are read from `pendingChoices jsonb`, which `MatchingStage` wrote from `TrackCandidate` objects the adapter already produced. D-71's nine methods are untouched. The client never sees a provider symbol other than `displayName` |
| **setlist.fm responses are always cached** | **Setlist selection spends zero budget** (D-191). `candidateSetlists` is materialised from already-cached `Setlist` rows at suspension time, per spec 13 §6's cost table. `SetlistGateway` gains no new caller |
| **Provider credentials never leave the secrets layer** | Unchanged. Nothing in the suspension payloads is a credential; `pendingChoices` holds provider track ids and public metadata only |
| **Provider state is read at runtime** | The resume path re-runs pre-flight through `ProviderRegistry`. A provider disabled while a job slept sends it to `blocked` (T-19), never to `failed` |
| **Playlist generation degrades, it does not fail** | Every staleness case in spec 13 §6 resolves to a report code and a continued job. An expired session is a designed screen with the user's choices pre-filled, not a dead end (D-197) |
| **A user-scoped resource returns 404, never 403** | All four new operations are sub-resources of `PlaylistGenerationJob`, filtered by the existing `PlaylistGenerationJobOwnerExtension` (D-157) before any voter runs. `ConcertOwnerExtension` is not touched. `UserTrackPreference` (D-198) is user-scoped and gets the same extension shape |
| **The backoffice edits behaviour, never credentials** | The one backoffice change is a read-only decision-count line on the existing dashboard panel (D-209). No new write action |
| **The OpenAPI spec is the single source of truth** | The four operations are declared on the existing `PlaylistGenerationJobResource`; `frontend/api/schema.d.ts` is regenerated before any client wiring (D-202) |

### Existing groundwork this design builds on, not around

| Already exists | Where | What this spec does with it |
|---|---|---|
| `PlaylistPipeline` + seven stages | `backend/src/Service/Playlist/` | Calls the same object graph. `SetlistSelectionStage` and `ReviewStage` already contain the mode guards; this spec makes them evaluate true |
| `JobState::AwaitingSetlistChoice` / `AwaitingVersionChoice` | `Model/JobState.php` | Reachable for the first time. No new case |
| `candidateSetlists` / `pendingChoices` / `userChoices` jsonb | `PlaylistGenerationJob` | Read and written by the four new operations. **No migration for any of them** |
| `app:playlist:expire-jobs` (D-138) | `Command/` | Already written and scheduled by spec 14; this spec is the first thing that gives it rows to find |
| `TrackResolution` (D-121) | `Entity/` | Read-through cache stays global and untouched. A *user* preference is deliberately a different table (D-198) |
| `derivePlaylistView()`, `polling.ts`, `reportCopy.ts` | `frontend/lib/playlist/` | Extended with two view states and the choice-label vocabulary. Polling termination is unchanged — both `awaiting_*` states already send no `Retry-After` (spec 14's polling contract) |
| `SetlistSelect` / `VersionSelect` / `Confirm` / `Resume` artboards | `docs/design/canvas/playlist-flow/` | Implemented. Two deliberate divergences, both named (D-194, D-195, D-205) |

---

## Goals

1. A user who wants control gets it in **at most two questions**, on web, iOS and Android.
2. **One pipeline, provable by test** — spec 13 §3 already stated the property the test asserts; this
   spec makes it a named, failing-on-fork integration test (D-189).
3. A 25-song setlist requires a **median of ≤ 5 decisions**, measured from real jobs rather than
   claimed (D-209).
4. A session abandoned for three days resumes with every prior choice intact; one abandoned for
   longer expires **visibly**, into a pre-filled new job rather than a blank one.
5. Nothing in the world moving underneath a sleeping job can fail it.
6. Zero additional setlist.fm budget, and a bounded, stated additional provider-search cost.

Non-goals, stated so scope stays closed: a general-purpose review UI, editing a playlist after it
exists, free-text track search, and any change to Fast mode's behaviour.

---

## User Stories

### US-1 — Pick which night

> **As a** user who went to a specific show, **I want** to choose which setlist the playlist is built
> from, **so that** I get the night I was actually at rather than the band's most recent festival slot.

**Acceptance criteria**

- **AC-1.1** — From the concert page, "Choose it yourself" starts a job with `mode = normal`; the job
  reaches `awaiting_setlist_choice` and the client renders `SetlistSelect.dc.html`.
- **AC-1.2** — Each candidate row shows **venue, city, event date, song count** and its setlist.fm
  provenance, sourced entirely from `candidateSetlists jsonb`. No setlist.fm request is issued while
  the job is suspended — asserted by a test that fails if `SetlistGateway` is invoked during the
  suspension or the choice submission.
- **AC-1.3** — Up to `SELECTION_WINDOW = 20` candidates are offered per band, ordered by `eventDate`
  descending, matching spec 13 §9's window.
- **AC-1.4** — The candidate whose `eventDate` equals the `Concert.date` is badged **"Same night"**
  and pre-selected. When none matches, the candidate D-132's automatic rule would have picked is
  pre-selected and badged with its `selectionReason`.
- **AC-1.5** — **Exactly one setlist**: no suspension. T-03's `only_one_available` fires, the job goes
  straight to `matching`, and the client shows progress with a line naming the setlist that was used.
- **AC-1.6** — **No usable setlist for any band**: T-10, `completed` / `no_source_material`, and the
  client renders the existing `DegradedBandUnknown` / `DegradedNoSongs` screen selected by
  `noSetlistCause` (D-183). Normal mode adds no new degraded screen.
- **AC-1.7** — **Multi-band concert**: one suspension, not one per band. The step shows a band
  selector; the submission carries a choice per band and is rejected (422) if any qualifying band is
  unanswered (D-190).
- **AC-1.8** — A band on the lineup with no usable setlist appears in the step as an explanatory row,
  not as an unanswerable question, and contributes `NO_SETLIST_FOR_BAND` to the report.

### US-2 — Decide only what is genuinely uncertain

> **As a** user facing a 25-song setlist, **I want** to confirm only the songs Setlistify is unsure
> about, **so that** getting the playlist I want does not cost me 25 taps.

**Acceptance criteria**

- **AC-2.1** — Only songs in spec 12's `CHOICE` band (`0.55 ≤ conf < 0.80`) surface as decisions.
  `AUTO_ACCEPT` songs are pre-resolved; `REJECT` songs are reported as `not_found` and are **not**
  presented as a decision, because there is nothing plausible to choose between.
- **AC-2.2** — Pre-resolved songs collapse into one summary band ("22 songs matched automatically"),
  expandable to a reviewable list, **never demanding a tap** — `VersionSelect.dc.html`'s green band.
- **AC-2.3** — Each decision card pre-selects Setlistify's top candidate, so **submitting the step
  with zero taps is a legitimate, complete path** and is equivalent to Fast mode's result for those
  songs.
- **AC-2.4** — Candidates are the top 3–5 `TrackCandidate`s persisted in `pendingChoices` at
  suspension. **No provider search is issued while the job is suspended, or on submission** —
  asserted by a test.
- **AC-2.5** — Confidence is legible **without a number**: each candidate carries a label from a
  closed vocabulary (`Top pick`, `Only match`, `Alternative`) and each card a plain-language reason
  ("Two releases exist", "Only a live version found"). No percentage, no score, no star rating
  appears anywhere in the rendered tree — asserted by a test (D-204).
- **AC-2.6** — A user may decline a song entirely ("none of these"), producing outcome `skipped` with
  reason code `USER_DECLINED`, which is a success path and not a miss in the match-rate denominator
  (spec 12 §9's *matchable* definition).
- **AC-2.7** — **Empty `CHOICE` band**: no version step. T-06 fires and the job proceeds to
  `building`, which is exactly the shared-pipeline property AC-7.1 asserts (D-195).

### US-3 — Walk away and come back

> **As a** user interrupted mid-flow, **I want** to return days later and continue, **so that** an
> interruption does not cost me the decisions I already made.

**Acceptance criteria**

- **AC-3.1** — A suspended job survives an app restart, a device restart and a change of platform:
  all state lives on the server, and the client resolves it on mount via the existing
  `GET /api/playlist-generation-jobs?concertId=` non-terminal query (spec 16's resolution-on-entry).
- **AC-3.2** — Reopening the concert shows `Resume.dc.html`'s banner **in place of** the generate
  trigger — no separate inbox, no notification to hunt for (D-207).
- **AC-3.3** — The banner leads with what is done ("22 of 25 songs are already decided"), in the
  **info** family, never warning or error (spec 16's D-168 colour discipline applies unchanged).
- **AC-3.4** — "Resume" re-enters the step the job's state names. "Start over" is visually demoted and
  is `POST …/cancel` followed by a fresh job (D-208), with a confirmation naming what is discarded.
- **AC-3.5** — A job suspended at `awaiting_setlist_choice` lives **7 days**; at
  `awaiting_version_choice`, **72 hours** — spec 13 §6's TTLs, unchanged, and neither is a client
  constant.
- **AC-3.6** — Resuming re-runs pre-flight. A provider disabled or a token expired while the job slept
  produces **`blocked`** with the payload retained (T-19), rendered by the existing
  `DegradedProviderDisabled` / `DegradedTokenExpired` screens with a resume affordance — never
  `failed`, never a dead end.

### US-4 — Be told when a paused session expired

> **As a** user returning after two weeks, **I want** to be told plainly that my paused session lapsed
> and to restart without re-deciding everything, **so that** expiry costs me a tap rather than my work.

**Acceptance criteria**

- **AC-4.1** — `app:playlist:expire-jobs` moves the job to `expired`, **keeps `userChoices`** and
  **drops `candidateSetlists` / `pendingChoices`** (spec 13 §6). No behaviour here is new; this spec
  is what first exercises it.
- **AC-4.2** — The client renders an `expired` view stating in plain language that the paused session
  lapsed and why (the candidates it held are no longer current), in the info family.
- **AC-4.3** — Its primary action starts a **new** job carrying `resumeFromJobId`, which pre-fills the
  setlist choice and every version choice whose song and candidate still exist (D-197). Expiry is
  visible, and it costs the user one tap, not a re-run of their decisions.
- **AC-4.4** — A pre-filled choice that can no longer be honoured (its candidate is gone) surfaces as
  a decision again rather than being silently dropped.

### US-5 — Not be asked the same thing twice

> **As a** user generating a second playlist for a band I see often, **I want** Setlistify to remember
> the version I picked last time, **so that** repeat generations ask me less each time.

**Acceptance criteria**

- **AC-5.1** — A submitted version choice writes a `UserTrackPreference` row keyed
  `(owner, provider, algorithmVersion, normalizedArtist, normalizedTitle)` → `providerTrackId`
  (D-198).
- **AC-5.2** — `MatchingStage` consults it **before** the `CHOICE` band is assembled. A song with a
  live preference whose `providerTrackId` is still present in the current candidate set is resolved
  to it and **does not become a decision**.
- **AC-5.3** — A preference is **never silent**: the song appears in the reviewable auto-band and
  carries report code `USED_YOUR_PREVIOUS_CHOICE`, so the user can see and change it (D-199).
- **AC-5.4** — A preference whose track is no longer among the candidates is ignored and the song
  becomes a decision again. A stale preference never forces a wrong track.
- **AC-5.5** — **Preferences never reach the shared `TrackResolution` cache.** A test asserts that
  submitting a version choice writes no row to, and mutates no row in, `TrackResolution` — one user's
  taste must not become every user's match (D-198).
- **AC-5.6** — Preferences are user-scoped and read through an owner-filtering query extension; there
  is no cross-user read path.

### US-6 — Go back without losing anything

> **As a** user part-way through, **I want** to step back and change my mind, **so that** reviewing my
> own decisions is not a risk.

**Acceptance criteria**

- **AC-6.1** — Moving between the version step and the confirm summary is **client-side navigation
  only**; it issues no request and cannot lose a draft (D-193).
- **AC-6.2** — Draft choices are held per job id in client storage, so backgrounding the app, a
  reload, or an app restart before submission preserves them.
- **AC-6.3** — `POST …/version-choices` is a **full replacement and idempotent**: re-submitting while
  the job is still `awaiting_version_choice` overwrites cleanly (D-192).
- **AC-6.4** — Stepping back from version selection to *setlist* selection is offered only while the
  job is still `awaiting_setlist_choice`. Once matching has run, changing the setlist is a new job,
  and the copy says so — matching results are frozen when `building` is entered, and spec 13 makes
  `building → matching` illegal by construction.
- **AC-6.5** — A submission arriving in the wrong state returns **422** with a typed violation, never
  a 409 and never a silent no-op; the client refetches the job and re-renders from server truth.

### US-7 — One pipeline, not two

> **As the** backend engineer, **I want** the shared pipeline enforced by a test, **so that** Normal
> mode cannot quietly fork into a parallel implementation.

**Acceptance criteria**

- **AC-7.1** — The test spec 13 §3 specifies exists and passes: *a Normal-mode job on a band with
  exactly one usable setlist and an empty `CHOICE` band produces a state sequence identical to the
  Fast-mode job for the same concert, and an identical set of `PlaylistTrack` rows* (D-189).
- **AC-7.2** — A static test asserts the mode is branched on in **exactly two places** —
  `SetlistSelectionStage` and `ReviewStage` — and nowhere else in `backend/src/Service/Playlist/`
  (D-188).
- **AC-7.3** — `JobStateMachine` remains the only writer of `PlaylistGenerationJob::$state`; the four
  new processors call it and never assign directly. The existing static scan (spec 13 AC-8.4) covers
  the new files.
- **AC-7.4** — No new `JobState` case, no new stage class, no new message class, no new handler.
- **AC-7.5** — Fast mode's behaviour is unchanged: spec 14's and spec 16's suites pass untouched.

### US-8 — The world moving does not break a sleeping job

> **As the** backend engineer, **I want** every staleness case decided in advance, **so that** resume
> degrades rather than errors.

**Acceptance criteria**

- **AC-8.1** — Spec 13 §6's staleness table is implemented case for case, with a test per row:
  corrected setlist (`setlistFingerprint` mismatch → re-match only changed songs, report
  `SETLIST_CORRECTED_SINCE_SELECTION`); bumped `algorithmVersion` (**keep explicit user choices**,
  re-score only unanswered songs, report `RESCORED_AFTER_ALGORITHM_UPDATE`); chosen candidate gone at
  insert (F-13, per-track `not_found`, `TrackResolution` deleted); provider disabled or token expired
  (T-19 `blocked`); concert deleted (T-18 `cancelled`); chosen setlist purged from cache (fall back to
  D-132, report `SELECTED_SETLIST_UNAVAILABLE`).
- **AC-8.2** — **No staleness case produces `failed`**, and none produces an HTTP error on the
  suspension endpoints.
- **AC-8.3** — The fingerprint is recomputed at resume, not at submission, so the comparison is against
  the state of the world when work actually restarts.

### US-9 — Know whether the decision count is actually small

> **As the** operator, **I want** the number of decisions Normal mode demands to be measured, **so
> that** "only 3 of 25" is a fact rather than an artboard.

**Acceptance criteria**

- **AC-9.1** — `choicesRequiredCount` and `choicesMadeCount` are columns on `PlaylistGenerationJob`,
  written at the version suspension and at submission (D-209).
- **AC-9.2** — The existing "Playlist generation (last 7 days)" dashboard panel gains, for
  `mode = normal` jobs: median and p95 `choicesRequiredCount`, the abandonment rate (share reaching
  `expired` from each suspended state), and the share of jobs completed with zero taps.
- **AC-9.3** — An investigate-threshold is recorded on the same footing as spec 13 §8's:
  **median `choicesRequiredCount` > `DECISION_BUDGET = 5`** means spec 12's `CHOICE` threshold needs
  revisiting, which is a legitimate outcome of this work and not a defect of this UI.
- **AC-9.4** — No write action is added to the backoffice.

---

## Technical Approach

### 1. What is genuinely new, stated as a list — D-188

Normal mode is **four API operations, one entity, two columns and two client view states.** Nothing
else. Set against spec 14's merged code:

```
backend/src/Service/Playlist/
  Stage/SetlistSelectionStage.php     ← MODIFIED: the mode guard now suspends (T-04)
  Stage/MatchingStage.php             ← MODIFIED: consults UserTrackPreference before banding
  Stage/ReviewStage.php               ← MODIFIED: the mode guard now suspends (T-07)
  Model/ReportCode.php                ← 2 cases: USED_YOUR_PREVIOUS_CHOICE, USER_DECLINED
  Choice/
    SetlistChoiceApplier.php          ← NEW. validates + applies a setlist choice, dispatches
    VersionChoiceApplier.php          ← NEW. validates + applies version choices, dispatches
    StalenessReconciler.php           ← NEW. spec 13 §6's table, one method per row
    PreferenceRecorder.php            ← NEW. writes UserTrackPreference (D-198)

backend/src/Entity/
  UserTrackPreference.php             ← NEW  (D-198)
  PlaylistGenerationJob.php           ← +choicesRequiredCount, +choicesMadeCount, +resumedFromJob

backend/src/State/{Provider,Processor}/Playlist/
  CandidateSetlistsProvider.php  SubmitSetlistChoiceProcessor.php
  PendingChoicesProvider.php     SubmitVersionChoicesProcessor.php

backend/src/Security/
  UserTrackPreferenceOwnerExtension.php   ← the ConcertOwnerExtension shape, verbatim

frontend/lib/playlist/
  view.ts          ← MODIFIED: two view states (choose_setlist, choose_versions) — D-202
  choices.ts       ← NEW. draft state, per job id, persisted (D-193, D-206)
  confidence.ts    ← NEW. confidence band → label vocabulary (D-204)
frontend/components/playlist/
  ModeSheet.tsx  SetlistPicker.tsx  VersionPicker.tsx  ConfirmSummary.tsx  ResumeBanner.tsx
```

**One migration**, carrying the `user_track_preference` table plus three nullable columns on
`playlist_generation_job`. The suspension payloads need none — spec 13 specified them and spec 14
shipped them.

**The mode is branched on in exactly two places.** That is D-130, and AC-7.2 is the static test that
keeps it true as the code ages. A third `if ($job->getMode() === JobMode::Normal)` anywhere under
`backend/src/Service/Playlist/` fails the build, which is the cheapest available answer to the
prompt's *"if it starts to feel like a parallel implementation, stop"*.

### 2. The four operations — D-190

Declared on the existing `PlaylistGenerationJobResource`; the regenerated OpenAPI document is the
source of truth and **no endpoint is listed in any README**. Every operation carries
`security: "is_granted('IS_AUTHENTICATED_FULLY')"` and is filtered by
`PlaylistGenerationJobOwnerExtension` before any voter runs, so another owner's id is a 404
byte-identical to a missing id (D-157, unchanged).

| Operation | Provider / processor | Request | Success | Errors |
|---|---|---|---|---|
| `GET /api/playlist-generation-jobs/{id}/candidate-setlists` | `CandidateSetlistsProvider` | — | **200** `CandidateSetlistsOutput` | **404** cross-owner or unknown · **422** unless state = `awaiting_setlist_choice` |
| `POST /api/playlist-generation-jobs/{id}/setlist-choice` | `SubmitSetlistChoiceProcessor` | `SetlistChoiceInput { choices: [{ bandId, setlistfmId }] }` | **202** + the job in `matching` (T-05) | **404** · **422** wrong state, unknown `bandId`, a `setlistfmId` not among that band's candidates, or a qualifying band unanswered |
| `GET /api/playlist-generation-jobs/{id}/pending-choices` | `PendingChoicesProvider` | — | **200** `PendingChoicesOutput` | **404** · **422** unless state = `awaiting_version_choice` |
| `POST /api/playlist-generation-jobs/{id}/version-choices` | `SubmitVersionChoicesProcessor` | `VersionChoicesInput { choices: [{ sourcePosition, segmentIndex?, providerTrackId \| null }] }` | **202** + the job in `building` (T-08) | **404** · **422** wrong state, unknown `sourcePosition`, or a `providerTrackId` not among that song's persisted candidates |

**Response shapes.** Both are projections of the persisted jsonb, with nothing re-derived:

```
CandidateSetlistsOutput
  jobId · expiresAt · concertId
  bands: [ { bandId, bandName, billingOrder,
             recommendedSetlistfmId,        ← what D-132 would have chosen
             recommendedReason,             ← selectionReason enum
             noSetlistCause,                ← non-null when this band has nothing (D-183)
             candidates: [ { setlistfmId, eventDate, venueName, cityName, countryCode,
                             tourName, songCount, isSameNight, url } ] } ]

PendingChoicesOutput
  jobId · expiresAt · songsTotal · autoResolvedCount · choicesRequiredCount
  autoResolved: [ { sourcePosition, bandName, sourceTitle, providerTrackId,
                    label, reasonCode, reasonParams } ]     ← reviewable, never a question
  decisions:    [ { sourcePosition, segmentIndex, bandName, sourceTitle,
                    reasonCode, reasonParams,               ← "Two releases exist"
                    candidates: [ { providerTrackId, title, artistName, albumName,
                                    releaseYear, durationMs, label } ] } ]
```

`label` is the closed vocabulary of D-204 — `top_pick`, `only_match`, `alternative`,
`your_previous_choice` — computed backend-side from the confidence band. **A raw confidence score is
never sent to the client on these two operations.** That is not decoration: a number that reaches the
client is a number that eventually gets rendered, and spec 15's whole argument for this screen is
that a person confirms a recommendation rather than audits an algorithm. (`PlaylistOutput.tracks[]`
keeps its existing `confidence` field for the backoffice-adjacent report; that is spec 14's shape and
is not changed here.)

**Ordering and multi-band.** `bands[]` is in `billingOrder` ascending — headliner first, because that
is how a lineup is *described* (D-25). The playlist that results is still built in stage order,
billing order reversed (D-133). Those two orderings are deliberately opposite and this spec does not
reconcile them; it simply obeys each in its own place.

**Why one suspension for all bands, not one per band.** A four-band bill would otherwise suspend four
times, dispatch four messages and give the user four separate returns to the app. The candidate
payload for every band is already materialised in one jsonb column at one suspension point, and
`GENERATION_MAX_BANDS = 4` bounds the page. One question, answered once.

### 3. The confirm step is client-side — D-194

`Confirm.dc.html` is drawn as "step 3 of 3", which reads like a third server state. **It is not, and
must not become one.** Spec 13's T-08 goes `awaiting_version_choice → building`; a twelfth state
(`awaiting_confirmation`) would add a transition, a TTL, an expiry case and a staleness case for a
screen that displays only numbers the client already holds.

So: the confirm summary is rendered from the draft in `choices.ts` plus `PendingChoicesOutput`, and
**"Build the playlist" *is* `POST …/version-choices`.** The step counter stays 3-of-3 for the user,
because the user's model of "three steps" is correct even though the server's model of "two
suspensions" is also correct. The count arithmetic the artboard insists on (22 automatic + 3
confirmed = 25) is computed from `autoResolvedCount + choices.length` and is traceable to the
previous screen, exactly as the artboard's note requires.

### 4. An empty `CHOICE` band skips both the version step and confirm — D-195

If matching leaves nothing ambiguous, T-06 fires and the job goes straight to `building`. The user
sees setlist selection, then progress, then the result — no version step, no confirm screen.

This is a **deliberate divergence from `Confirm.dc.html`**, and it is the price of AC-7.1: the
shared-pipeline test asserts that a Normal-mode job with one setlist and an empty `CHOICE` band is
byte-identical to the Fast-mode job. Suspending anyway to show a summary screen would break exactly
the property the prompt demands be provable, in exchange for a screen whose entire content is "there
was nothing to decide". The result screen names the setlist that was used and the count that matched,
so nothing is hidden.

The client compensates where it costs nothing: after submitting the setlist choice, the progress
screen carries the line *"We'll ask you about anything uncertain"*, so a user who lands directly on
the result was not promised a question that never came.

### 5. Preference memory — D-198, D-199

The prompt asks to *"remember a user's version preferences where it is safe to do so"*. The safety
qualifier is the whole design.

```
UserTrackPreference
  id                integer (SERIAL)
  owner             FK User            ← user-scoped: 404 not 403
  provider          string(32)         ← StreamingProviderInterface::key()
  algorithmVersion  smallint           ← the same invalidation lever as TrackResolution (D-121)
  normalizedArtist  string(200)        ← BandResolver::normalize() of the EXPECTED artist
  normalizedTitle   string(200)        ← SongNormalizer comparisonCore
  providerTrackId   string             ← what the user actually chose. Never NULL
  chosenAt          timestamptz
  usedCount         int
  UNIQUE (owner_id, provider, algorithm_version, normalized_artist, normalized_title)
```

The key is deliberately **spec 12's `TrackResolution` key plus `owner`**, so the two tables answer the
same question at two scopes and a preference is a per-user override of a global resolution. `Song`
and `Band` FKs are deliberately absent: the point is that picking the studio *Fake Plastic Trees*
once should hold the next time any concert produces that song.

**Four constraints, each load-bearing:**

1. **It never writes to `TrackResolution`.** That cache is global and keyed without an owner; one
   user's taste leaking into it would silently re-aim every other user's match. AC-5.5 is a test, not
   a comment.
2. **It is consulted before banding, not after.** A remembered song is resolved during matching and
   therefore never counted in `choicesRequiredCount` — which is the entire point, and also what makes
   D-209's metric show the improvement over time.
3. **It is applied only when the remembered track is still among the current candidates.** Otherwise
   it is ignored (AC-5.4). A preference is a tie-break among plausible options, never a bypass of
   matching.
4. **It is announced, not silent** — `USED_YOUR_PREVIOUS_CHOICE` in the reviewable auto-band. The
   product's honesty rule does not stop applying because the guess came from the user.

**No cross-song inference.** A user who picks three live versions is *not* inferred to prefer live
versions generally. That is a plausible-sounding feature with no evidence behind it and a bad failure
mode — silently aiming an entire playlist at the wrong recordings — and it belongs to whatever prompt
has data to justify it.

**No preference-management screen** in this prompt. A preference is visible where it acts (the
auto-band) and overridden by picking something else, which writes the new value. A settings surface
for bulk review is a feature, and it is not this one. *(Open question Q-3.)*

### 6. Quota: the arithmetic, worked — D-200, D-201

**setlist.fm: zero.** Both suspensions read cached rows. `SetlistGateway` gains no caller (AC-1.2).

**Provider search: one search per song, unchanged — with one bounded exception.** Spec 12's D-120
forbids a speculative second search and names prompt 17 as the single permitted exception, for the
cover case: when a cover search lands in the `CHOICE` band, the performing band's *own* recording is
worth finding, because that is precisely the case Normal mode exists to fix.

**D-200 — the second search is scripted at suspension time, not offered on demand.** It runs during
`MatchingStage`, once, for `CHOICE`-band songs whose match was resolved via the cover path (spec 12
§3's attribution), and is capped at `MAX_COVER_RESEARCH = 5` per job. It is *not* a "search for
something else" button: an on-demand search is unbounded, arrives while the user is watching, and
turns a bounded per-job cost into a per-tap one. **There is no free-text track search in this
prompt.**

**The YouTube arithmetic, for prompt 18.** Spec 12 §7 and `docs/external-apis.md` give 100 units per
search against 10,000 units/day for the whole application:

| | Searches | Units | Share of daily quota |
|---|---|---|---|
| Fast, 25-song setlist, cold cache | 25 | 2,500 | 25 % |
| Normal, same setlist, no preferences | 25 + ≤5 | ≤ 3,000 | ≤ 30 % |
| Normal, same setlist, warm `TrackResolution` | ~0–5 | ≤ 500 | ≤ 5 % |
| **Application ceiling, cold** | — | 10,000 | **~3 Normal generations per day, application-wide** |

**Three cold Normal-mode generations exhaust YouTube's entire daily quota for every user of the
product.** That is not a Normal-mode defect — Fast mode is already four — and this spec does not
solve it. It flags it as the constraint prompt 18 must design against, alongside spec 13's `blocked`
/ `provider_quota` path which already handles exhaustion without failing a job. Suspension itself is
free: `pendingChoices` holds results already paid for, so a user thinking for three days costs
nothing. **The cost is in matching, not in asking.**

### 7. Client shape — D-202 … D-208

**One route still.** `app/(app)/concerts/[id]/playlist.tsx` gains two view states from
`derivePlaylistView()`: `choose_setlist` and `choose_versions`. Spec 16's D-162 argued that splitting
result variants into routes would make a server state change a navigation event; that argument is
stronger here, where the server can move a job from under the user's feet (expiry, `blocked`). The
confirm summary is a **sub-step within** `choose_versions`, held in client state — which is what makes
AC-6.1's "back costs nothing" true by construction.

**Polling needs no change.** Both `awaiting_*` states already send no `Retry-After`, and spec 16's
rule is "stop when the header is absent" without enumerating state names. The client stops polling on
suspension and resumes after a submission returns 202, using the same hook.

**The mode sheet ships — D-203, closing spec 16's Q-2.** `Main.dc.html`'s recommendation stands: no
forced choice. "Generate playlist" remains one tap into Fast; "Or choose it yourself →" opens the
sheet (bottom sheet on phone, inline expansion on desktop) with two cards — **"Fast — We pick
everything"** and **"Choose it yourself — Pick the show, then confirm anything uncertain"**. The words
*Fast mode* and *Normal mode* are ours and never appear in the UI.

**Confidence vocabulary — D-204.** `confidence.ts` maps a backend `label` to a rendered chip and
nothing else; there is no client-side scoring. AC-2.5's test renders every card variant and asserts no
digit-plus-percent, no `confidence` numeric, and no star glyph appears.

**Draft state — D-206.** `choices.ts` keys drafts by job id in `AsyncStorage` (web:
`localStorage` via the same shim spec 16 uses), cleared on successful submission, on `cancel`, and on
`expired`. The draft is a convenience; **the server is the authority the moment a submission
succeeds**, and a 422 always triggers a refetch-and-rerender rather than a client-side reconciliation
(AC-6.5).

**Resume banner — D-207.** Rendered by `PlaylistSection.tsx` in place of `GenerateTrigger` whenever a
non-terminal job for the concert is in either `awaiting_*` state. No inbox, no notification: reopening
the concert is the whole re-entry path, per `Resume.dc.html`'s note.

**"Start over" — D-208.** `POST …/cancel` (which spec 14 shipped and spec 16 left unused), then a
fresh `POST /api/playlist-generation-jobs`. It is not a server-side reset, because D-129's partial
unique index permits one live job per concert per provider and a cancel-then-create keeps that
invariant obvious. The confirmation names what is lost.

### 8. The report's row actions stay read-only — D-205

Spec 16's D-171 deferred `Report.dc.html`'s per-row actions ("Pick a version", "Use the live version",
"Add anyway") to this prompt. **This spec declines them, and closes spec 16's Q-1 that way.**

Those actions, taken on a report, mean changing a playlist that already exists — which is *editing a
playlist after creation*, explicitly out of scope in prompt 17's own brief and unavailable through the
frozen nine-method port in any case (D-71 has no remove-track method; changing a track would mean
deleting and rebuilding the provider playlist).

The actions belong to the **pre-build** version-selection screen, where this spec implements them in
full. `ResultMostly`'s CTA therefore stays **"See what's missing"**, and the report remains the honest
account of what happened rather than a half-working editor. A user who wants a different version
generates again — and D-198's preference memory means the second run asks about the songs they cared
about, which is the same outcome by a route that does not need a tenth port method.

### 9. Test plan

| Area | Test |
|---|---|
| **Shared pipeline** | AC-7.1's state-sequence + `PlaylistTrack` equality test, Fast vs Normal, over the test-double adapter. AC-7.2's static mode-branch scan. AC-7.3's `JobStateMachine` scan |
| **Full interactive flow** | Functional: create → `awaiting_setlist_choice` → choose → `matching` → `awaiting_version_choice` → choose → `building` → `completed`, asserting the persisted `PlaylistTrack` set matches the choices |
| **Suspend / resume** | Job suspended, entity manager cleared, resumed in a new request; plus a client test that a remount resolves the live job and restores the draft |
| **Expiry** | `app:playlist:expire-jobs` over both TTLs; asserts `userChoices` kept, payloads dropped, and that `resumeFromJobId` pre-fills correctly (AC-4.3) |
| **Staleness** | One test per row of spec 13 §6's table (AC-8.1), each asserting a report code and a non-`failed` outcome |
| **Edge counts** | Band with one setlist (no suspension), band with none, concert where no band has one, empty `CHOICE` band, all-declined submission |
| **Budget** | `SetlistGateway` never invoked during suspension or submission; provider `searchTrack()` never invoked during suspension or submission; cover re-search capped at 5 |
| **Preferences** | Applied, announced, ignored when stale, and **never written to `TrackResolution`** (AC-5.5) |
| **Ownership** | Another owner's job id returns 404 byte-identical to a missing id on all four operations; `UserTrackPreference` has no cross-user read path |
| **Frontend** | Rendered-tree tests for both new view states; no raw confidence number (AC-2.5); no error token on any suspended, expired or blocked view (spec 16 D-168) |
| **Contract** | `frontend/api/schema.d.ts` regenerated; a hand-written request/response shape in `frontend/lib/playlist/` fails the existing static check (spec 16 D-177) |

---

## Out of Scope

| Not here | Where |
|---|---|
| Editing a playlist after it exists — including the report's per-row actions | Nowhere yet. D-205 explains why the port cannot support it today |
| Free-text or on-demand track search | Deliberately declined (D-200). Unbounded quota cost |
| Playback of the generated playlist | Prompt 19 |
| A preferences management screen | Open question Q-3; not this prompt |
| Cross-song preference inference ("this user likes live versions") | Declined. No evidence, bad failure mode |
| Any change to Fast mode's behaviour | Explicitly excluded by the prompt; AC-7.5 asserts it |
| Re-tuning spec 12's `AUTO_ACCEPT` / `CHOICE` thresholds | Prompt 18's harness. This spec **measures** the decision count (D-209) so that tuning has data |
| YouTube's quota problem | Prompt 18. Flagged with arithmetic (D-201), not solved |
| Push notification when a long generation finishes | Recorded as a risk by spec 16; still not built |
| A cancel affordance outside "Start over" | Spec 15 drew none (spec 16 D-179 stands) |

---

## Dependencies

**Must be true before implementation starts**

1. **Spec 14 merged**, with `PlaylistGenerationJob`, both `awaiting_*` states, `candidateSetlists`,
   `pendingChoices`, `userChoices`, `app:playlist:expire-jobs` and the seven existing operations in
   place. *(True — merged.)*
2. **Spec 16 merged**, with `playlist.tsx`, `derivePlaylistView()`, `polling.ts` and `reportCopy.ts`.
   *(True — merged, plus the result-state-gaps fix.)*
3. **Spec 13 approved and unamended** — this spec implements its §6 rather than re-deciding it.
   *(True — approved 2026-08-23.)*
4. **The `CHOICE` band is populated in practice.** Everything here assumes `0.55 ≤ conf < 0.80`
   selects a small, non-empty set on real data. If spec 14's harness run showed a systematically
   empty or systematically huge band, that is a spec 12 threshold question and it blocks this work.
   **Verify against the harness output before starting** — it is the one dependency that is a fact
   about data rather than about code.
5. **A working provider adapter with a linked account** (Spotify, spec 10) for the interactive tests.
6. **`docs/design/canvas/playlist-flow/`** artboards — `SetlistSelect`, `VersionSelect`, `Confirm`,
   `Resume`, `Main` — as the visual source of truth. *(Delivered.)*

**Ordering within the branch**

1. Migration (`user_track_preference`, three job columns) → 2. the two stage guards + the four
   operations → 3. `StalenessReconciler` and its tests → 4. preference memory → 5. regenerate
   `frontend/api/schema.d.ts` → 6. client view states, mode sheet, resume banner → 7. the
   decision-count panel line → 8. docs.

**Documentation to update, in this branch**

- API Platform resource annotations, so the OpenAPI document regenerates. **No endpoint list in any
  README.**
- `docs/architecture.md` — §8's pipeline diagram gains the two suspension points as reachable, and §10
  gains `UserTrackPreference`.
- `docs/external-apis.md` — the Normal-mode row of §6's quota arithmetic.
- `docs/specs/2026-08-24-playlist-fast-mode-ui.md` — mark Q-1 and Q-2 resolved, pointing here.
- No new environment variable. `MAX_COVER_RESEARCH`, `DECISION_BUDGET` and the label vocabulary are
  calibration constants beside spec 12's, not env vars.

---

## Risks and Open Questions

### R — Risks carried

| # | Risk | Likelihood / impact | Mitigation |
|---|---|---|---|
| R-1 | **The decision count is not small.** If real setlists put 8–12 songs in the `CHOICE` band, `VersionSelect` becomes the exhausting screen the whole design exists to avoid | Medium / **high — make-or-break** | D-209 measures it from day one; `DECISION_BUDGET = 5` is the recorded investigate-threshold. The fix is a spec 12 threshold change, and this spec says so in advance so that outcome reads as success, not failure |
| R-2 | **Suspended sessions hold candidates that decay.** A 72-hour-old candidate can be a deleted YouTube video or a delisted track | High / low | Spec 13's TTLs, unchanged. Staleness resolves per §6's table to a report code (AC-8.1), and expiry is **visible** with choices pre-filled (AC-4.3), never a silent reset |
| R-3 | **YouTube's quota makes Normal mode application-wide expensive** — roughly three cold generations per day (D-201) | Certain / high, from prompt 18 onward | Flagged with arithmetic for prompt 18. `blocked` / `provider_quota` already handles exhaustion honestly. Warm `TrackResolution` reduces it by an order of magnitude |
| R-4 | **The confirm step diverges from the artboard** when the `CHOICE` band is empty (D-195) | Certain / low | Named, with the reason (AC-7.1's provability). The progress line sets expectations so no promised question goes missing |
| R-5 | **Preference memory picks the wrong track persistently** — a user's one-off choice becomes their default forever | Low / medium | Applied only when the remembered track is still a candidate, announced via `USED_YOUR_PREVIOUS_CHOICE`, and overridden by simply choosing again. Never written to the global cache (AC-5.5) |
| R-6 | **Two suspensions is two abandonment points.** A user may never return to either | Medium / medium | Measured (AC-9.2's abandonment rate by state). The resume banner sits where the trigger was, so returning costs one tap and no hunting |
| R-7 | **Draft state and server state disagree** after a job moves underneath the user | Medium / low | The server is authoritative on every 422; the client refetches and re-renders rather than reconciling (AC-6.5) |

### Q — Open questions, each with a recommendation

**Q-1 — Should the setlist step let the user paste or search a setlist.fm URL directly?**
A user who knows the exact show is currently limited to the 20 most recent cached candidates, and the
band's `SELECTION_WINDOW` may not contain the night they attended (an older concert being logged
retroactively is the realistic case).
**Recommendation: no, not in this prompt.** Every candidate today is free because it is cached;
resolving an arbitrary URL is a live `SetlistGateway` call against a 1,440/day application-wide budget
spent from an interactive screen where a user can retry at will. That is a budget decision
(`CLAUDE.md`'s cache rule) and it deserves its own consideration — most likely a rate-limited,
per-user-capped lookup — rather than being smuggled in as a text field. The gap is real; record it as
a candidate backlog item rather than closing it here.

**Q-2 — Should `choicesRequiredCount` be capped, with the overflow auto-resolved?**
If R-1 materialises and a setlist produces 12 decisions, this spec presents all 12.
**Recommendation: no cap.** A cap would hide the very number D-209 exists to measure and would make
the metric describe the UI rather than the matcher. Present them all, measure honestly, and fix it at
the threshold — where the problem actually is. Revisit only if the measured median exceeds
`DECISION_BUDGET` and a threshold change proves insufficient.

**Q-3 — Do preferences need a management surface, and should they be opt-in?**
This spec makes remembering default-on with no toggle, on the grounds that it is the user's own
choice about their own account, is announced wherever it acts, and is overridden by choosing again.
**Recommendation: default-on, no toggle, no management screen in this prompt** — but if a preference
ever becomes visible in a way a user cannot trace to a decision they made, that judgment is wrong and
the surface becomes required. Worth a deliberate check at review.

**Q-4 — Is "Start over" the right verb for a suspended session that has become `blocked`?**
`Resume.dc.html` draws Resume / Start over for a healthy suspension. A `blocked` job's real action is
"re-link your account", after which the *original* job continues (T-19 retains the payload).
**Recommendation: reuse the existing `DegradedTokenExpired` / `DegradedProviderDisabled` screens
with their own primary action, and show the resume banner's counts as a secondary line** — so a
blocked-while-suspended job reads as "paused, and here is the one thing to fix", not as two competing
offers. One rendering rule, no new screen.

**Q-5 — Should a Normal-mode job that reaches the version step with zero decisions still show a
screen?** D-195 says no.
**Recommendation: confirm no, and accept the artboard divergence** — but this is the decision most
worth a second opinion, because it is the one place a user asked for control and was given none.

---

## Review requested

The five decisions most worth pushing back on before implementation:

1. **D-195 / Q-5 — an empty `CHOICE` band skips the version *and* confirm steps**, so a user who
   chose "Choose it yourself" may be asked exactly one question. It buys the provable shared pipeline
   AC-7.1 demands, and it diverges from `Confirm.dc.html`.
2. **D-205 — the report's per-row actions are declined, not implemented**, closing spec 16's Q-1 the
   other way. Acting on them post-build is playlist editing, which prompt 17 puts out of scope and
   the nine-method port cannot do.
3. **D-198 — preference memory is a new user-scoped table that never touches `TrackResolution`**, with
   no cross-song inference and no management screen (Q-3). It is the only genuinely new behaviour in
   this spec.
4. **D-200 / D-201 — no on-demand search; the one permitted second search is scripted and capped at
   five per job.** The YouTube arithmetic that follows (~3 cold Normal generations per day
   application-wide) is flagged for prompt 18, not solved here.
5. **D-209 — the decision count is instrumented with a recorded investigate-threshold
   (`DECISION_BUDGET = 5`)**, and this spec states in advance that exceeding it means changing spec
   12's thresholds rather than this UI.

**Resolved on approval (2026-08-25):** all five decisions above approved as written, no changes.
Q-1 (setlist.fm URL lookup) and Q-2 (capping decisions) recorded as declined per their
recommendations. Q-3 (preference management surface) and Q-4 (blocked-while-suspended copy) approved
per their recommendations, to be revisited only if the stated trigger condition occurs. Q-5 confirmed:
an empty `CHOICE` band skips both the version and confirm steps.
