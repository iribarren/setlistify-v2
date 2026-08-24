# FEATURE — Playlist generation: Fast mode (UI)

| | |
|---|---|
| **Spec ID** | `2026-08-24-playlist-fast-mode-ui` |
| **Backlog prompt** | `docs/prompts/16-playlist-fast-mode-ui.md` |
| **Command** | `/feature playlist-fast-mode-ui` |
| **Primary agent** | `frontend-engineer` |
| **Type** | **FEATURE — implementation follows this document directly.** One branch `feature/playlist-fast-mode-ui`, one PR |
| **Depends on** | `11` provider configuration (merged) · `13` playlist pipeline (approved) · `14` fast-mode backend (**approved 2026-08-23**) · `15` playlist flow design (canvas delivered) |
| **Implemented by** | *(this is the implementation)* — extended by `17` normal mode, `19` playback, `21` sharing |
| **Decisions** | **D-161** – **D-181** |
| **Status** | **Approved 2026-08-24** |

---

## Overview

### What this feature is

This is where the product's promise stops being a backend fact and becomes something a person can
use. Prompt 14 shipped the pipeline, the job state machine and seven API operations; prompt 15 drew
every screen the flow can land on. **This spec decides nothing about either.** It decides how the
Expo client drives those operations, which designed screen each server state maps to, and how the
client stays honest while doing it.

The user-visible outcome is one sentence: **from a tracked concert, one tap produces a playlist in
the user's linked streaming account, with per-song progress while it builds and a plain-language
account of anything that could not be matched.**

The quality bar, restated from the prompt because implementation is exactly where it gets lost:
**a partial result is a success.** "14 of 19 matched" is the typical Tuesday. It gets the
information-blue treatment prompt 15 drew for it — never an error colour, never a warning triangle,
never the `ErrorState` component.

### This spec follows prompt 14's API and prompt 15's design; it re-decides neither

- Every endpoint, request field, response field, status code, `Retry-After` cadence, `ETag` rule and
  state name comes from **spec 14** (`docs/specs/2026-08-23-playlist-fast-mode-backend.md`,
  D-145–D-160) verbatim. No shape is re-derived, and nothing is hand-written that the OpenAPI
  document already describes.
- Every state name, transition, `blockedReason`, `failureReason`, `resultKind` and report code comes
  from **spec 13** (`docs/specs/2026-08-23-spike-playlist-pipeline.md`, D-125–D-144) verbatim.
- Every screen, layout, headline, tone decision and colour family comes from **spec 15's canvas**
  (`docs/design/canvas/playlist-flow/`, artboards listed in `canvas.json`). The artboards, not this
  document, are the visual source of truth.
- This document's decisions, **D-161–D-181**, are only about things none of those decided: the client
  file layout, the polling implementation, the state→screen mapping, the report-code copy catalogue,
  the provider-selection rule, and what this prompt deliberately leaves un-wired for prompt 17.

Where implementation reveals one of those documents was wrong, it is corrected **in that document, in
this branch**, not diverged from silently.

### Load-bearing rules this spec does not reverse

| Rule (`CLAUDE.md`) | How this design honours it |
|---|---|
| **The streaming port is the only way to reach a provider** | The client never talks to Spotify or YouTube. Every provider interaction is an API call to our backend; the only provider-specific string that reaches the UI is `displayName` from `GET /api/config/providers`. A static test asserts no provider literal in `frontend/lib/playlist/` or the playlist components (D-169) |
| **Provider state is read at runtime, not baked in** | Provider availability, the default, and playback classification are read from `GET /api/config/providers` on every relevant render — never a build-time constant, never a hardcoded list. A provider disabled in `/admin` mid-incident changes the UI without a client release |
| **Provider credentials never leave the secrets layer** | The client holds no client id, no secret, no provider token. Re-linking goes through the existing `frontend/lib/streaming/linkAccount.*` flow (spec 10), which already keeps the token exchange backend-side |
| **Playlist generation degrades, it does not fail** | The client has exactly three screens that read as a failure, matching the pipeline's exactly three routes to `failed` (F-14, F-15, block-cycle exhaustion). Everything else is a result or a wait (D-166, D-168) |
| **Generate types from OpenAPI, never hand-roll them** | `frontend/api/schema.d.ts` is regenerated before any client code is written; every request/response type in `frontend/lib/playlist/` is an alias of `components["schemas"][…]` (D-177) |
| **A user-scoped resource returns 404, never 403** | Enforced server-side by `PlaylistOwnerExtension` / `PlaylistGenerationJobOwnerExtension` (D-157). The client renders a 404 on a job or playlist as "not found", identically to a missing id — it never renders "not yours" and never branches on ownership |
| **The backoffice is not part of the contract** | Nothing in this feature reads `/admin`. The operator's effect on the client arrives only through `GET /api/config/providers` |

### Existing groundwork this design builds on, not around

| Existing | Used how |
|---|---|
| `frontend/components/concert/ReservedSection.tsx` (`testID="reserved-playlist"`) | The dashed placeholder on the concert detail screen is **replaced** by the real playlist section; a new `reserved-playback` placeholder takes its place inside it for prompt 19 (D-176) |
| `frontend/lib/concerts/queries.ts` | The TanStack Query conventions (query keys, invalidation, optimistic patterns) are copied exactly; playlist queries are a sibling module, not a new pattern |
| `frontend/lib/streaming/` (`useStreamingAccounts`, `linkAccount.web/native`, `StreamingAccountRow`) | Connection status, the "Needs reconnect" badge and the OAuth re-link path are reused verbatim — the token-expired screen must look like a place the user has been before (`DegradedTokenExpired.dc.html`) |
| `frontend/components/concert/DeleteConfirmation.tsx` | The delete-playlist confirmation reuses this shape, with copy amended per D-151/D-173 |
| `frontend/components/state/{EmptyState,ErrorState,LoadingState}` | `LoadingState` for the initial fetch only. **`ErrorState` is reserved for the three genuine failures** and for transport errors — never for a partial result, never for a `blocked` job (D-168) |
| `frontend/lib/concerts/errorMessage.ts`, `frontend/lib/streaming/errorMessage.ts` | The established "server code → human sentence" pattern; the report-code catalogue (D-167) is the same idea applied to a larger, exhaustive enum |

---

## User Stories

### US-1 — One tap, a playlist

> As a **user looking at a concert I tracked**, I want to tap once and get a playlist in my streaming
> account, so that I can replay the show without doing any work.

**Acceptance criteria**

- **AC-1.1** The concert detail screen renders a **Playlist** section with a primary
  **"Generate playlist"** button when the concert has no playlist and no live job — the trigger drawn
  in `Main.dc.html`, phone and desktop.
- **AC-1.2** Tapping it issues `POST /api/playlist-generation-jobs` with `{ concertId }` and, when the
  user must choose, `{ provider }`. A **201** and a **200** (a live job already exists, D-129) are
  handled identically: both navigate to the progress screen for the returned job.
- **AC-1.3** The provider sent is derived at runtime from `GET /api/config/providers` ∩ the user's
  `connected` `StreamingAccount`s (D-169). With exactly one candidate, no chooser is shown. With more
  than one and an `isDefault` among them, the default is used and the alternative is available on the
  result. With more than one and **no** default (`isDefault: false` on every item — AC-7.5 of spec 11),
  the user is asked before the job starts.
- **AC-1.4** No provider key literal (`"spotify"`, `"youtube"`, …) appears anywhere in the feature's
  source; a static test asserts it.
- **AC-1.5** The flow works on web, iOS and Android from the same component tree; the only
  platform-split files are ones that already exist (`linkAccount.*`).

### US-2 — See it happening

> As a **user waiting ~30 seconds**, I want to see per-song progress, so that the wait reads as work
> happening rather than a hang.

**Acceptance criteria**

- **AC-2.1** The progress screen renders `Progress.dc.html`: an **indeterminate** ring (not a
  percentage), three named steps — *Found the setlist* → *Matching songs — X of Y* → *Saving the
  playlist* — and the "you can leave this screen" reassurance.
- **AC-2.2** The matching step shows `songsProcessed` of `songsTotal` and updates as the poll returns
  new values. The counter never goes backwards and never exceeds the total.
- **AC-2.3** Polling honours the server's `Retry-After` (1 s while `matching`/`building`, 3 s while
  `queued`/`resolving_setlist`) and **stops when the header is absent** — the client does not
  special-case eleven state names to know it is done (D-163).
- **AC-2.4** Each poll sends `If-None-Match`; a **304** is treated as "unchanged" and re-renders
  nothing (D-164).
- **AC-2.5** No screen in this feature ever shows an indeterminate spinner with no accompanying text
  for longer than one poll interval.

### US-3 — Leave and come back

> As a **user who closed the app mid-generation**, I want the result waiting for me, so that I never
> have to babysit a progress bar.

**Acceptance criteria**

- **AC-3.1** Navigating away from the progress screen issues no cancel and no abort. The job is
  server-side; the client simply stops polling (D-165).
- **AC-3.2** Returning to the concert — from anywhere, on any of the three platforms, including a cold
  start — resolves the current job via `GET /api/playlist-generation-jobs?concertId=…` and lands on
  the correct screen for its state: progress if still active, the result variant if `completed`, the
  designed degraded screen if `blocked`.
- **AC-3.3** On native, backgrounding the app pauses polling; foregrounding refetches immediately
  rather than waiting out the interval (D-165).
- **AC-3.4** Backgrounding on a real iOS device (not only a simulator) is verified manually before the
  PR is opened, and the result is recorded in the PR description.

### US-4 — Read an honest result

> As a **user whose setlist did not fully match**, I want the outcome stated plainly, so that I trust
> the tracks that did make it.

**Acceptance criteria**

- **AC-4.1** All four variants render from live data, each matching its artboard:
  `ResultFull.dc.html`, `ResultMostly.dc.html`, `ResultBarely.dc.html`, `ResultNothing.dc.html`.
- **AC-4.2** The variant is chosen by the rule in D-166 — from `resultKind` and the counters the API
  already returns, never re-derived from the track list.
- **AC-4.3** **No partial variant uses an error or destructive colour token, an error icon, or the
  word "error", "failed", "problem" or "sorry".** A test asserts this against the rendered tree for
  each of the three partial variants (D-168).
- **AC-4.4** The `0 / N` variant keeps "View the setlist" available — "we found what they played"
  succeeded even where "we found it on the provider" did not.
- **AC-4.5** The result headline leads with what exists before naming what is missing, per
  `ResultMostly.dc.html` ("Playlist's ready — 5 songs need a pick", not "5 songs failed").

### US-5 — Understand every miss

> As a **user reading the report**, I want a specific sentence per song, so that I can tell a catalog
> gap from a data quirk.

**Acceptance criteria**

- **AC-5.1** The report screen renders `Report.dc.html`: only the songs that need a look, never the
  matched ones — a 25-song setlist with 3 gaps is a 3-row screen.
- **AC-5.2** Each row's sentence comes from the report-code catalogue (D-167), keyed by
  `PlaylistTrack.reasonCode` with `reasonParams` interpolated (`COVER_OF` names the original artist;
  `LIVE_VERSION_ONLY` says only a live recording is available; `TRACK_NOT_IN_CATALOG` says it is not
  on the provider).
- **AC-5.3** **No raw code, enum value, HTTP status or stack detail is ever rendered.** An unknown
  code — one the backend added ahead of the client — falls back to a specific, honest generic sentence
  and is logged in development, never displayed as the code itself.
- **AC-5.4** The catalogue is exhaustive over the generated `reasonCode` union: adding a code
  backend-side and regenerating the schema **breaks the client build** until copy exists for it.
- **AC-5.5** Job-level codes from `Playlist.reportSummary` (e.g. `BANDS_OMITTED_FOR_LENGTH`,
  `SETLIST_MAY_BE_STALE`, `RESUMED_MID_INSERTION`) render as a short note above the per-song list,
  from the same catalogue.

### US-6 — Meet every degraded state as a designed screen

> As a **user hitting a constraint that is not my fault**, I want a screen that explains it and tells
> me whether to act or wait, so that I never see a raw error.

**Acceptance criteria**

- **AC-6.1** Each of the six designed degraded states renders its artboard, driven by the server field
  named in D-170's table — never by an HTTP status and never by string-matching a message.
- **AC-6.2** A `blocked` job is **never** rendered as an error. `GET` returns 200 with `blockedReason`
  and `resumableAfter`; the UI shows the designed wait, with a countdown where `resumableAfter` is a
  concrete instant.
- **AC-6.3** Where recovery is automatic, **no retry button is offered** — `DegradedBudgetExhausted`
  and `DegradedQuotaExhausted` deliberately have none, because retrying spends someone else's budget
  for nothing.
- **AC-6.4** `needs_reauth` offers **"Reconnect \<Provider\>"**, going straight into the existing OAuth
  link flow. On success the job query is invalidated; the server re-queues the blocked job when the
  account returns to `connected` (F-06), so the UI shows it picking back up rather than asking the
  user to start over (D-174).
- **AC-6.5** `provider_disabled` renders `DegradedProviderDisabled.dc.html` and, when another provider
  is both enabled and connected, offers it inline as a same-screen alternative that starts a new job
  for that provider (D-175). Toggling the provider off in `/admin` mid-generation produces this screen,
  not a crash.
- **AC-6.6** `no_source_material` is distinguished by cause: **band unknown** →
  `DegradedBandUnknown.dc.html`, **band known but no setlist for this show** → `DegradedNoSongs.dc.html`.
  Both are HTTP 200 `completed` jobs and neither is styled as an error.

### US-7 — Retry and delete, safely

> As a **user whose generation failed, or who no longer wants a playlist**, I want both actions to be
> unambiguous, so that I never end up with a duplicate or a wrong impression.

**Acceptance criteria**

- **AC-7.1** A `failed` job offers **"Try again"** → `POST /api/playlist-generation-jobs/{id}/retry`,
  which returns **202** and the job in `queued`; the UI returns to the progress screen. Retry is offered
  **only** on `failed` (D-172) — never on `blocked`, never on a partial result.
- **AC-7.2** `failureReason = creation_indeterminate` renders the one honest gap (F-14): *"We may have
  created an empty playlist called '<name>' in your account. We won't create another unless you tell us
  to."* with a confirming **"Create it anyway"** → `POST …/create-anyway`.
- **AC-7.3** Deleting a playlist requires confirmation, and the confirmation **states plainly that the
  provider-side playlist remains**: *"removed from Setlistify; the playlist stays in your \<Provider\>
  account until you delete it there"* (D-151, D-173). Copy that implies otherwise is a bug.
- **AC-7.4** After a successful `DELETE /api/playlists/{id}` (204), the concert page returns to the
  "Generate playlist" trigger state, and the concert and job history are untouched.

### US-8 — The playlist lives on the concert page

> As a **user coming back to a concert weeks later**, I want the playlist and its match state right
> there, so that the page is where the show lives.

**Acceptance criteria**

- **AC-8.1** The concert detail screen renders `ConcertPlaylist.dc.html`: the playlist header, the
  match badge, the first tracks with a "+ N more" affordance, and the actions.
- **AC-8.2** The match state **travels with the playlist** — "14 of 19 matched" and the route into the
  report stay visible on every later visit, not only once at generation time.
- **AC-8.3** A `reserved-playback` region renders directly **beneath** the tracklist, visibly reserved
  for prompt 19, using `ReservedSection`. The `reserved-note` and `reserved-share` placeholders are
  unchanged.
- **AC-8.4** `GET /api/playlists?concertId=` is the only source for this section; the client does not
  cache a playlist shape derived from a job response.

---

## Technical Approach

### 1. Where the code goes — D-161

Sub-project: **`frontend/` only.** No backend change is required by this spec; if one proves necessary,
it is made in this branch and written back into spec 14.

```
frontend/
  app/(app)/concerts/[id]/
    index.tsx                  ← playlist section replaces reserved-playlist (D-176)
    playlist.tsx               ← progress + result (one route, state-driven — D-162)
    playlist-report.tsx        ← the report screen
  lib/playlist/
    queries.ts                 ← the only module that calls the playlist endpoints
    polling.ts                 ← Retry-After / ETag / AppState polling (D-163, D-164, D-165)
    view.ts                    ← derivePlaylistView(): server state → one view state (D-166)
    reportCopy.ts              ← reasonCode + params → a sentence (D-167)
    providerChoice.ts          ← config ∩ connected accounts → the provider to use (D-169)
    types.ts                   ← aliases of generated schema types only (D-177)
    index.ts
  components/playlist/
    GenerateTrigger.tsx  GenerationProgress.tsx  ResultCard.tsx
    ReportList.tsx  DegradedState.tsx  PlaylistSection.tsx
    DeletePlaylistConfirmation.tsx  index.ts
```

**One route, not four.** `playlist.tsx` renders whichever view state `derivePlaylistView()` returns —
progress, one of four results, or one of six degraded screens. Splitting them into routes would put
navigation logic in the same place as polling and make "the job changed state while you were looking at
it" a navigation event instead of a render. **D-162.**

### 2. Polling — implement spec 13's contract, add nothing — D-163, D-164, D-165

| Concern | Implementation |
|---|---|
| Cadence | TanStack Query `refetchInterval`, seeded from the response's `Retry-After` header. The 1.5 s figure in spec 13 §7 is the client's floor; the header is the authority |
| Stopping | `refetchInterval: false` when the response carries **no** `Retry-After`. This is the whole termination rule — terminal, blocked and suspended states all stop without being enumerated client-side |
| Conditional requests | The fetch layer stores the last `ETag` per job id and sends `If-None-Match`. **304** keeps the cached data and the previous render |
| Backgrounding | An `AppState` listener sets the interval to `false` in `background`/`inactive` and refetches immediately on `active`. Web uses `visibilitychange` through the same hook |
| Resolution on entry | Mounting the concert or playlist route resolves the live job with `GET /api/playlist-generation-jobs?concertId=` filtered to the non-terminal states, falling back to the most recent terminal job, then to `GET /api/playlists?concertId=` |
| Errors | A transport failure retries with backoff and keeps showing the last known progress. **A failed poll is not a failed generation** and must never replace the progress screen with an error |

Push was not chosen — spec 13 chose polling with an `ETag` and a server-driven cadence precisely because
it behaves the same on Expo web, iOS and Android and survives backgrounding without a socket to
reconnect. This spec implements that and does not introduce a second mechanism.

### 3. Server state → screen — D-166, D-170

One pure function, `derivePlaylistView(job, playlist, providers, accounts)`, returning a discriminated
union. Pure, exhaustively tested, and the only place this mapping exists.

**Result variants**, from `resultKind` and the counters already on `PlaylistGenerationJobOutput` /
`PlaylistOutput` — never recomputed from the track array:

| Condition | View | Artboard |
|---|---|---|
| `resultKind = complete` (`matchedCount + lowConfidenceCount = songsTotal − skippedCount`) | `result_full` | `ResultFull.dc.html` |
| `resultKind = partial`, `matchRate ≥ 0.5` | `result_mostly` | `ResultMostly.dc.html` |
| `resultKind = partial`, `0 < matchRate < 0.5` | `result_barely` | `ResultBarely.dc.html` |
| `resultKind = no_tracks_matched` (`matchRate = 0`, no provider playlist) | `result_nothing` | `ResultNothing.dc.html` |
| `resultKind = no_source_material`, band unresolved | `degraded_band_unknown` | `DegradedBandUnknown.dc.html` |
| `resultKind = no_source_material`, band resolved, no setlist for the show | `degraded_no_songs` | `DegradedNoSongs.dc.html` |

The **0.5 boundary** is this spec's only new number, and it is a copy decision, not a quality one: at or
above half, the honest headline is "playlist's ready, N need a pick"; below it, the honest headline
names the catalog as thin. It lives as a single named constant, `MOSTLY_MATCHED_FLOOR`, so moving it is
a one-line change with a test.

**Degraded and failure states**, from `blockedReason` / `failureReason`:

| Server field | View | Artboard | Recovery offered |
|---|---|---|---|
| `blockedReason = setlistfm_budget` | `blocked_budget` | `DegradedBudgetExhausted.dc.html` | None — countdown to `resumableAfter` |
| `blockedReason = provider_quota` \| `provider_rate_limit` | `blocked_quota` | `DegradedQuotaExhausted.dc.html` | None — shows matched-so-far as saved |
| `blockedReason = needs_reauth` | `blocked_reauth` | `DegradedTokenExpired.dc.html` | **Reconnect** (OAuth) |
| `blockedReason = provider_disabled` | `blocked_disabled` | `DegradedProviderDisabled.dc.html` | **Use \<other provider\>**, or wait |
| `blockedReason = upstream_unavailable` | `blocked_upstream` | `DegradedQuotaExhausted.dc.html` layout, upstream copy | None — auto-resumes |
| `failureReason = creation_indeterminate` | `failed_indeterminate` | Result layout, failure copy | **Create it anyway** |
| `failureReason = unknown_provider`, or block cycles exhausted | `failed_generic` | `ErrorState` + **Try again** | **Retry** |
| `state = expired` \| `cancelled` | `idle` | Trigger | Generate again (a **new** job) |

`upstream_unavailable` has no artboard of its own — prompt 15 drew six degraded states and this is a
seventh the pipeline can produce. It reuses the quota layout with its own sentence rather than inventing
a screen; noted as open question **Q-3**.

### 4. Provider selection — D-169

```
candidates = providers(GET /api/config/providers).filter(enabled)
             ∩ accounts(GET /api/streaming-accounts).filter(status = connected)
```

- 0 candidates → the trigger becomes a link-an-account prompt, reusing the streaming settings copy.
- 1 candidate → used silently; no chooser.
- \>1 with an `isDefault` among them → the default is used; the alternative appears on the result and on
  the `provider_disabled` screen, never as a pre-flight question.
- \>1 with no default (a valid state, spec 11 AC-7.4) → a bottom sheet asks, using
  `Components.dc.html`'s sheet pattern.

`GET /api/config/providers` is unauthenticated and `no-store`; it is fetched with a short client-side
`staleTime` so an operator's mid-incident toggle reaches an open app quickly.

### 5. Report copy — D-167

`reportCopy.ts` is a `Record<ReasonCode, (params) => string>` typed against the **generated** union, so a
new backend code is a compile error, not a silent gap. Sentences follow `Report.dc.html`'s register:
name a cause a non-technical person recognises, never a mechanism. The backend stores codes and
parameters, never English (D-141) — this file is where the English lives, which is also what makes
translation a client change rather than a migration.

### 6. Colour discipline — D-168

Partial and blocked states use the **info** family; `ResultBarely` tunes toward warning per its artboard.
The error/destructive tokens are reachable only from `failed_generic`, `failed_indeterminate` and
transport failures. Enforced by a test that renders each partial and blocked view and asserts no error
token, no error icon `testID`, and none of the forbidden words appears in the tree (AC-4.3).

### 7. What this prompt deliberately does not wire — D-171, D-179

- **The report is read-only in fast mode.** `Report.dc.html` draws per-row actions ("Pick a version",
  "Use the live version", "Add anyway"). Those actions are version selection — prompt 17's
  `POST …/version-choices`. This prompt renders the rows, the reasons and the counts; the row actions
  arrive with Normal mode. `ResultMostly`'s primary CTA is therefore **"See what's missing"** rather than
  "Review the 5 songs" until prompt 17 makes reviewing actionable. Open question **Q-1**.
- **The mode chooser is not built.** `Main.dc.html` shows "Or choose it yourself →" opening a sheet with
  Fast and Choose-it-yourself. Choose-it-yourself is prompt 17. The trigger ships as the one-tap primary
  button the design already makes the default; the sheet lands with the mode it introduces. Open
  question **Q-2**.
- **No cancel UI.** `POST …/cancel` exists server-side; prompt 15 drew no cancel affordance, and inventing
  one here would be design done in code. It stays unused until designed. **D-179.**
- **No push notification** for a long generation. Recorded as a risk, not built.

---

## Out of Scope

| Not here | Where |
|---|---|
| Normal mode: setlist selection, version selection, suspend/resume, the report's row actions | Prompt 17 (`SetlistSelect`, `VersionSelect`, `Confirm`, `Resume` artboards) |
| In-app playback, now-playing controls — the reserved region stays a placeholder | Prompt 19 |
| Notes and reviews | Prompt 20 |
| Sharing a playlist or a share card | Prompt 21 |
| The YouTube adapter (the UI is already provider-agnostic; nothing changes when it lands) | Prompt 18 |
| Any backend change: endpoints, job states, report codes, thresholds | Specs 13 and 14 — amended there if wrong, never worked around here |
| Backoffice screens and metrics | Spec 14 §7 (shipped) |
| Push notifications on completion | Not scheduled; see Risks |
| A cancel affordance | Undesigned; see D-179 |

---

## Dependencies

**Must be true before implementation begins:**

| # | Dependency | Status |
|---|---|---|
| 1 | `docs/specs/2026-08-23-playlist-fast-mode-backend.md` (spec 14, D-145–D-160) — the seven operations live on `master` | **Approved 2026-08-23**; verify merged before starting |
| 2 | `docs/specs/2026-08-23-spike-playlist-pipeline.md` (spec 13, D-125–D-144) — states, `blockedReason`, `failureReason`, `resultKind`, report codes | Approved |
| 3 | `docs/design/canvas/playlist-flow/` (prompt 15) — `Main`, `Progress`, `ResultFull`, `ResultMostly`, `ResultBarely`, `ResultNothing`, `Report`, the six `Degraded*`, and `ConcertPlaylist` artboards | Delivered |
| 4 | `docs/specs/2026-08-22-backoffice-provider-configuration.md` (spec 11) — `GET /api/config/providers` with `key`, `displayName`, `enabled`, `playbackMode`, `isDefault` | Merged |
| 5 | `docs/specs/2026-08-22-streaming-port-and-account-linking.md` (spec 10) — `StreamingAccount` status and the OAuth link flow the re-link path reuses | Merged |
| 6 | `docs/design/canvas/screens/` (prompt 06) and the design system canvas — `ReservedSection`, `Components.dc.html`'s sheet, `StreamingAccountRow` | Merged |
| 7 | **`frontend/api/schema.d.ts` regenerated** from the running backend's OpenAPI document, in this branch, **before** any client code is written | **Blocking first task** |
| 8 | A local environment where a generation can actually be run end to end (`docker compose up`, a linked account, a concert whose headliner has setlist.fm presence) | Required for AC-3.4 and the manual pass |

---

## Risks

| # | Risk | Mitigation |
|---|---|---|
| R-1 | **Backgrounding behaves differently on a real iOS device than in a simulator.** The simulator keeps timers alive in ways the device does not | AC-3.4 requires a real-device pass. The design is already resilient: the job is server-side and entry always re-resolves from the API rather than from in-memory state |
| R-2 | **A red error colour creeps into a partial result.** The single most likely way this feature ships wrong | AC-4.3's assertion runs in CI against all three partial variants, and the reviewer is asked to open the artboards side by side |
| R-3 | **Generation regularly exceeds ~30 s**, past what anyone will watch | Instrumented already: spec 14's backoffice panel reports p50/p95 with a >90 s investigate threshold. If p95 crosses it, the answer is a completion notification — a separate prompt, explicitly not built here |
| R-4 | **The report's designed row actions imply Normal mode is present.** A user tapping "Pick a version" and finding nothing would be worse than not offering it | D-171: rows are read-only this prompt and the CTA copy is adjusted accordingly. Q-1 asks for confirmation |
| R-5 | **A backend report code lands without client copy**, rendering a raw enum | AC-5.4's exhaustive `Record` makes it a build failure; AC-5.3's fallback makes even a runtime surprise a sentence, never a code |
| R-6 | **Polling drift** — a client that keeps polling a terminal job, or stops polling a live one | The `Retry-After` presence rule is a single branch (D-163) with a test per state class, rather than a list of state names that must be kept in sync with the backend |
| R-7 | **`upstream_unavailable` has no artboard** | D-170 reuses the quota layout with its own copy; Q-3 asks whether prompt 15 should draw it |
| R-8 | **Three platforms, one component tree** — a native-only regression slipping through a web-only test run | Tests run on all three platform targets per AC-9.5 below; the existing `DateField.web/native` fork is the only precedent for splitting, and this feature adds no new one |

---

## Test Plan

Every item names its kind. The suite makes no outbound call and no real provider call.

| # | Kind | Asserts |
|---|---|---|
| T-1 | Unit | `derivePlaylistView()` over a fixture per row of D-166's and D-170's tables — sixteen cases, exhaustive over `resultKind`, `blockedReason` and `failureReason` |
| T-2 | Unit | `MOSTLY_MATCHED_FLOOR` boundary: `matchRate` exactly 0.5 → `result_mostly`; just below → `result_barely` |
| T-3 | Unit | `reportCopy` is total over the generated code union; every entry interpolates its params; the unknown-code fallback returns a sentence, never the code |
| T-4 | Unit | `providerChoice`: 0 / 1 / many-with-default / many-without-default, and disabled-but-connected excluded |
| T-5 | Component | Each of the four result variants renders its designed headline, count and CTAs |
| T-6 | Component | **AC-4.3** — no error token, error icon or forbidden word in `result_mostly`, `result_barely`, `result_nothing`, or any `blocked_*` view |
| T-7 | Component | Each of the six degraded states renders its distinguishing element (the "known on setlist.fm" badge, the reset countdown, the "Needs reconnect" badge, the alternative-provider row) |
| T-8 | Component | The report lists only unmatched/needs-attention rows; a 25-song fixture with 3 gaps renders 3 rows |
| T-9 | Integration (mocked fetch, fake timers) | Progress: `songsProcessed` advances across polls; the counter is monotonic; `Retry-After` drives the interval; a missing `Retry-After` stops it; a 304 causes no re-render |
| T-10 | Integration | Backgrounding: `AppState` → background pauses; → active refetches immediately |
| T-11 | Integration | Re-entry: mounting with a live job lands on progress; with a completed job lands on the result; with a blocked job lands on the degraded screen |
| T-12 | Integration | Retry: a `failed` job's "Try again" posts to `/retry` and returns to progress; **no retry affordance exists on any `blocked` or partial view** |
| T-13 | Integration | `create-anyway` is offered only for `creation_indeterminate` and posts once |
| T-14 | Integration | Delete: confirmation copy contains the provider-side-remains sentence; 204 returns the page to the trigger state |
| T-15 | Integration | Re-link: "Reconnect" enters the link flow; on success the job query is invalidated and the UI shows the job resuming |
| T-16 | Static | No provider key literal in `frontend/lib/playlist/` or `frontend/components/playlist/` |
| T-17 | Static | No hand-declared request/response interface in the feature — every type traces to `frontend/api/schema.d.ts` |
| T-18 | Manual | Full generation on web, iOS and Android against a running backend; iOS backgrounding on a real device (AC-3.4); a provider toggled off in `/admin` mid-generation (AC-6.5) |

---

## Documentation to update, in this branch

- `frontend/README.md` — the `lib/playlist/` module, the polling hook and the report-copy catalogue.
- `docs/architecture.md` §on the client — the playlist section of the concert screen and the
  state→screen mapping as the client's counterpart to spec 13's state machine.
- **No endpoint is documented anywhere** — the OpenAPI document remains the single source of truth, and
  this feature adds none.
- No new environment variable, no `.env.example` change, no backoffice change expected. If any appears,
  `docs/env-vars.md` and `docs/architecture.md` follow in the same branch.
- `docs/prompts/README.md` — mark 16 done on merge.

---

## Risks and Resolved Questions

Resolved on approval — 2026-08-24. All recommendations accepted as written.

1. **Report row actions — resolved: not shipped at all until prompt 17.** `ResultMostly`'s primary CTA
   is "See what's missing"; prompt 17 restores the designed row actions and copy. *(D-171.)*

2. **Mode chooser sheet — resolved: not shipped.** Fast mode ships as the one-tap trigger only; the
   "choose it yourself" link and sheet land with prompt 17.

3. **`upstream_unavailable` artboard — resolved: reuse `DegradedQuotaExhausted`'s layout** with its own
   sentence ("we're having trouble reaching \<Provider\> — we'll keep trying"). Revisit with a dedicated
   artboard only if it proves common in practice.

4. **`GET /api/config/providers` refetch cadence — resolved: 60-second `staleTime`**, plus a refetch on
   app foreground and before every generation start.

5. **"Generate with the other provider" on results — resolved: `DegradedProviderDisabled` only**, not on
   `ResultBarely` or other result variants. Revisit when prompt 18 makes two providers real.
