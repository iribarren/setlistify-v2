# SPIKE — Playlist Generation Pipeline: from a tracked concert to a real playlist

| | |
|---|---|
| **Spec ID** | `2026-08-23-spike-playlist-pipeline` |
| **Backlog prompt** | `docs/prompts/13-spike-playlist-pipeline.md` |
| **Command** | `/spec spike-playlist-pipeline` |
| **Primary agent** | `backend-engineer` |
| **Type** | **SPIKE — a design and a set of decisions, not an implementation.** No branch, no code, no migration |
| **Depends on** | `09` — setlist.fm integration (merged) · `10` — streaming port and account linking (merged) · `11` — backoffice provider configuration (merged) · `12` — song matching spike (approved 2026-08-23) |
| **Implemented by** | `14` — playlist fast mode backend · `16` — fast mode UI · `17` — normal mode · `18` — YouTube adapter |
| **Decisions** | **D-125** – **D-144** |
| **Status** | **Approved — 2026-08-23.** All four open questions resolved (see *Risks and Open Questions* → *Resolved on approval*); nothing deferred |

---

## Overview

### The design, stated first

**One job row, one message, one handler, one pipeline — and a state machine that treats *waiting* as
a first-class state rather than a failure.**

Prompt 12 answered *"which track is this song?"*. This document answers everything around it, and
the answer is shaped by two facts that between them determine almost every decision below.

The first is that **generation is slow**: a 25-song setlist is 25 provider searches at 150–250 ms
each, plus playlist creation and insertion — four to eight seconds of pure network on a warm
resolution cache, longer on a cold one, and far past any sane HTTP timeout. Generation is therefore
asynchronous, and *asynchronous* is not a detail of the transport: it means the job is a persisted
entity with an observable state, not a promise held in memory by a request that has already ended.

The second is that **failure is the normal case**. Bands with no setlist.fm data, songs absent from
a catalog, a daily budget spent by lunchtime, a token that expired between song 8 and song 9, an
operator disabling YouTube mid-afternoon because its quota ran out — these are not exceptions to the
happy path, they *are* the path, most days, for some fraction of jobs. A pipeline that models them
as errors will feel broken while working exactly as designed.

So the central move of this design is to split "not succeeding" into **three genuinely different
things**, and give each its own state:

1. **`completed` with a partial result** — the setlist was found, some songs matched, some did not.
   This is a **success**. `CLAUDE.md`'s degradation rule says so, prompt 14's quality bar says so,
   and the state machine says so by having no other place to put it.
2. **`blocked`** — nothing is wrong with the job; the world is temporarily not ready. The budget
   resets at midnight, the operator re-enables the provider, the user re-links their account. The job
   keeps everything it has already computed and resumes from exactly where it stopped. **This state
   is the single most load-bearing idea in the document**, because every one of prompt 14's
   "recoverable state" acceptance criteria collapses into it.
3. **`failed`** — a genuine defect or an indeterminate provider outcome that no amount of waiting
   fixes. Rare, terminal until a human retries, and always carrying a typed reason.

Everything else follows: a per-song progress counter in a column rather than a log line, so polling
is a cheap read; matching *before* creating the provider playlist, so the irreversible step happens
last and quota exhaustion never leaves an empty playlist in someone's account; an insertion watermark
so a retry resumes rather than restarts; and two numbers — generation time and match rate — written
to columns on the job row from the first commit, because prompt 14's own brief calls them "the two
numbers that matter" and a number that is not stored is a number nobody will ever have.

### What this document is, and what prompt 14 may assume

Prompt 09 ends at *"here are the songs setlist.fm recorded, in playing order"* — real `App\Entity\
Setlist` and `App\Entity\Song` rows, reached only through `App\Service\Setlist\SetlistGateway`.
Prompt 10 ends at *"the port can search a track, create a playlist and add tracks to it"* — nine
frozen methods on `App\Service\Streaming\StreamingProviderInterface`, six typed exceptions, and
`App\Service\Streaming\Link\StreamingTokenManager` as the only thing that refreshes a token. Prompt
11 ends at *"provider state is a runtime read"* — `App\Service\Provider\ProviderRegistry`. Prompt 12
ends at *"this song resolves to that track, with this confidence, in this band"*.

**This document is the machinery that calls all four in order, survives each of them failing, and
tells the user the truth about what happened.** It is written so that prompt 14 writes code rather
than makes decisions, and so that prompt 17 adds two `if` statements and four endpoints rather than a
second pipeline. Every question prompt 14's brief defers to "whatever prompt 13 decided" is answered
here, numerically, with nothing left as TBD.

### Load-bearing rules this spec does not reverse

| Rule | Source | How this design honours it |
|---|---|---|
| The streaming port is the only way to reach a provider | `CLAUDE.md`, `docs/architecture.md` §4 | The pipeline type-hints `StreamingProviderInterface` and resolves it through `App\Service\Streaming\StreamingProviderLocator`. `App\Service\Playlist\` contains no provider symbol and no provider key literal (D-125, AC-8.4) |
| The port is frozen at nine methods | D-71 | Nothing here adds a method. Quota pre-flight, playlist reconciliation and cost accounting are all designed *around* the frozen interface — D-136 and D-137 name exactly what that costs and why the cost is worth paying |
| `SetlistGateway` is the only door to setlist.fm, and responses are always cached | `CLAUDE.md`, D-58 | Setlist selection reads **cached `Setlist`/`Song` rows** and spends budget only when a band's index has never been fetched — at most one page per band per generation (D-131). `App\Service\Playlist\` holds no HTTP client and no `SetlistFmClient` reference; `SetlistGatewayIsOnlyDoorTest` stays green |
| Provider state is read at runtime via `ProviderRegistry` | `CLAUDE.md`, D-89 | `ProviderRegistry::isAvailable()` is re-checked at **every stage boundary**, not once at dispatch — a provider disabled at song 12 stops the job at song 12 (D-134, F-06) |
| Playlist generation degrades, it does not fail | `CLAUDE.md` | Partial success is `completed`, not `failed` (D-139). Every row of the failure taxonomy (§4) has a decided, non-error user-facing behaviour. The only three ways to reach `failed` are enumerated and none of them is "some songs were missing" |
| A user-scoped resource returns 404, never 403 | `CLAUDE.md`, D-27, `docs/architecture.md` §11 | `Playlist` and `PlaylistGenerationJob` copy `Concert`'s shape exactly: a Doctrine query extension filters every read to the current owner *before* a voter runs (D-143). The backoffice reads across owners through Doctrine directly and never touches those extensions (D-47) |
| The backoffice edits behaviour, never credentials — and is not part of the API contract | `CLAUDE.md`, D-46 | The generation views are **read-only** (D-142). No endpoint in this design is listed in a README; the OpenAPI spec regenerates from prompt 14's API Platform resources |
| Provider credentials never leave the secrets layer | `CLAUDE.md` | The job row holds a provider *key* and a `StreamingAccount` id. It never holds a token; `StreamingTokenManager::usableTokens()` supplies one per call and nothing persists it (D-135) |
| CI runs no integration tests against real external APIs | D-2, D-70, D-85 | Every test this design implies runs against a test-double adapter (`TestDoubleProviderIsDiscoverableTest`'s shape, AC-9.5) and committed fixtures |

### Existing groundwork this design builds on, not around

| Already in place | Where | Used for |
|---|---|---|
| `StreamingProviderInterface` — `searchTrack()`, `createPlaylist()`, `addTracks()`, `playlistDeepLink()`, `playlistEmbedUrl()` | `backend/src/Service/Streaming/` | Every provider interaction in the pipeline. Nine methods, unchanged |
| The six typed provider exceptions — `TokenExpiredException`, `RateLimitedException` (with `retryAfterSeconds`), `QuotaExhaustedException`, `NotFoundException`, `RegionRestrictedException`, `ProviderUnavailableException` | `backend/src/Service/Streaming/Exception/` | **The spine of §4's taxonomy.** Prompt 10 already produced exactly the vocabulary this design needs; §4 maps each one to a state rather than inventing a parallel set |
| `App\Service\Provider\ProviderDisabledException` and `App\Service\Streaming\UnknownProviderException` | `Service/Provider/`, `Service/Streaming/` | F-06 and F-13. Both already exist and are already typed |
| `StreamingTokenManager::usableTokens()` — proactive, single-flight per account via `symfony/lock`, flips to `needs_reauth` on unrecoverable failure (D-79, D-80) | `backend/src/Service/Streaming/Link/` | The pipeline never calls `refreshToken()`. It calls `usableTokens()` before each provider call and catches `TokenExpiredException` (F-05) |
| `ProviderRegistry::isAvailable()` / `configFor()` / `all()`, Redis-cached with explicit invalidation | `backend/src/Service/Provider/` | Provider selection and every mid-run availability re-check |
| `SetlistGateway::fetchArtistSetlistsPage()` returning `CachedFetch` with `reason ∈ {budget_exhausted, rate_limited, upstream_unavailable}` and `budgetResetAt` (D-63) | `backend/src/Service/Setlist/` | F-01. **`budgetResetAt` is literally the `resumableAfter` value the `blocked` state needs** — prompt 09 already computed it |
| `SetlistNormalizer::hydrateSetlistsPage()` hydrates full `Song` rows from the index payload (it shares `hydrateOne()` with the detail endpoint) | `backend/src/Service/Setlist/` | **Setlist selection costs zero extra setlist.fm calls** once a band's index is cached — the songs are already there (D-131) |
| `Setlist::$songCount` / `$isEmpty` / `$eventDate`, `Song::$position` / `$setLabel` | `backend/src/Entity/` | The substantiality rule (D-132) and order preservation (D-140) read these directly |
| `ConcertBand::$billingOrder` (0 = headliner, D-25) | `backend/src/Entity/` | Multi-band ordering (D-133) |
| `symfony/messenger` + `symfony/lock` in `composer.json`; `MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages` in `.env.example` | `backend/composer.json`, `.env.example` | **The variable exists; no transport, message or handler is wired.** `src/Message/` and `src/MessageHandler/` contain a README apiece saying exactly that. D-125 is where that changes |
| `TrackResolution` + `TrackResolutionStore`, keyed by `provider \| algorithmVersion \| normalizedArtist \| normalizedTitle` (D-121) | prompt 12 §8 (to be built by 14) | The reason a *retry* is cheap: a resumed job re-resolves nothing it already resolved |
| `App\Service\Admin\AuditLogger`, `App\Field\MaskedEmailField`, the setlist.fm dashboard panel (D-67) | `backend/src/Service/Admin/`, `Field/` | The shape D-142's backoffice additions copy rather than re-invent |
| `ConcertOwnerExtension` (D-27) | `backend/src/Filter/` or `Doctrine/` per prompt 05 | The exact pattern `PlaylistOwnerExtension` copies (D-143) |

---

## Goals

| Goal | Success looks like |
|---|---|
| Prompt 14 implements without making a design decision | Every state, transition, threshold, key, TTL, batch size and column in this document is a number or a name, not a range or a "consider" |
| Waiting is not failing | Budget exhaustion, quota exhaustion, an expired token and a disabled provider all land in **`blocked`**, keep every byte of work already done, and resume. None of them reaches `failed` |
| A partial playlist is a success | A 14-of-19 job is `completed`, renders green in prompt 16, and carries a stored per-song report explaining the five |
| A retry can never duplicate | Two mechanisms, both named and both persisted: the **creation marker** (D-136) and the **insertion watermark** (D-137). The residual window is one provider call wide, and the one case that cannot be closed behind the frozen port is surfaced to the user rather than silently gambled on |
| Normal mode is two `if`s, not a second pipeline | Prompt 17 adds `awaiting_setlist_choice` and `awaiting_version_choice` to an existing state machine and four endpoints to an existing resource. A test asserts both modes run the same `PlaylistPipeline::run()` (AC-4.2) |
| The wait is accounted for | Per-song progress is a column, updated per song, readable by a 1.5-second poll that works identically on Expo web, iOS and Android — including across backgrounding (D-128) |
| The two numbers exist from day one | `durationMs`, per-stage timings, and six outcome counters are **columns on the job row**, aggregated by a backoffice panel prompt 14 ships (D-141, D-142) — not a logging aspiration |
| Nothing here is a workflow engine | One entity, one handler, eleven states, no DSL, no plugin points, no generic step registry. Prompt 13's own risk note is the acceptance criterion |

---

## User Stories

A spike's stories are about the readers of the document, not the users of the product — the same
convention as spec 12. Each acceptance criterion is a property of *this document*, checkable by
reading it.

### US-1 — Implement the pipeline without further design work

> As the **backend engineer implementing prompt 14**, I want the job model, its states, its
> transitions and what is persisted at each one specified exactly, so that I write a state machine
> rather than invent one.

**Acceptance criteria**

- **AC-1.1** §1 names every state, classifies each as active / suspended / blocked / terminal, and
  gives a complete transition table with the trigger for each edge. No state is reachable that the
  table does not list.
- **AC-1.2** §1 states what is persisted on entry to each state, and in which transaction boundary.
- **AC-1.3** §2 gives the entity sketches for `PlaylistGenerationJob`, `Playlist` and `PlaylistTrack`
  with every column named and typed, consistent with `docs/architecture.md` §10.
- **AC-1.4** §3 gives the pipeline's stages in order, names the class that owns each, and says which
  stages are shared between the two modes and which are mode-gated.
- **AC-1.5** The Messenger configuration — transport, routing, retry policy, worker count — is
  specified concretely (D-125, D-144), given that none of it currently exists.

### US-2 — Know what happens to every failure mode

> As the **backend engineer**, I want a typed error, a landing state and a retry answer for each of
> the twelve failure modes prompt 13 enumerates, so that I never have to invent one mid-implementation
> and never write a bare `catch (\Throwable)`.

**Acceptance criteria**

- **AC-2.1** §4 contains one row per failure mode from prompt 13's list, plus the four this design
  discovered, each with: trigger, typed error, landing state, retryability, and the user-facing
  behaviour.
- **AC-2.2** No row resolves to "throw" or "fail the job" unless the *only* honest answer is failure —
  and §4 names the three cases where it is.
- **AC-2.3** The setlist.fm budget-exhaustion and provider quota-exhaustion answers are both decided,
  including the "refuse upfront or stop partway" question prompt 13 asks explicitly (D-134, D-135).
- **AC-2.4** Region restriction and a vanished track id are shown to be **per-track outcomes**, not
  job failures, and the resolution-cache invalidation this implies (prompt 12 §8) is named.

### US-3 — Retry without duplicating

> As the **product owner**, I want to know exactly why pressing "try again" cannot produce a second
> Spotify playlist or a doubled track list — and, where a gap is unclosable, I want to be told rather
> than reassured.

**Acceptance criteria**

- **AC-3.1** §5 names the idempotency key, says what it is computed from, and where it is persisted.
- **AC-3.2** The creation marker and the insertion watermark are both specified, with the transaction
  ordering that makes them work.
- **AC-3.3** The residual window is stated in provider calls, and the one indeterminate case is given
  an explicit, user-visible resolution rather than a silent guess (D-136).
- **AC-3.4** The database-level guarantee against two concurrent jobs for the same concert and
  provider is named as a constraint, not a convention.

### US-4 — Add Normal mode without a rewrite

> As the **backend engineer implementing prompt 17**, I want to know before I start that suspension is
> already in the state machine, and exactly where partial state lives, how long it lives, and what
> happens when the world moves under a suspended job.

**Acceptance criteria**

- **AC-4.1** §6 names the two suspension points, the state each uses, and what is persisted at each.
- **AC-4.2** The mechanism by which "both modes share one pipeline" is *testable* is specified, not
  asserted.
- **AC-4.3** Expiry is a number, with a sweeper named, and it differs between the two suspension
  points with a reason (D-138).
- **AC-4.4** Staleness on resume — a corrected setlist, a bumped `algorithmVersion`, a vanished
  candidate, a disabled provider — has a decided behaviour per case, and none of them is "fail".

### US-5 — Watch a generation happen, on three platforms

> As the **frontend engineer implementing prompt 16**, I want the progress mechanism chosen for me,
> with the Expo web-versus-native reasoning written down, so that I build one thing rather than two.

**Acceptance criteria**

- **AC-5.1** §7 chooses one mechanism and rejects the alternatives with platform-specific reasons.
- **AC-5.2** The poll cadence, the response shape's progress fields, and the server-side hint that
  controls cadence are specified.
- **AC-5.3** Backgrounding — leaving the screen, and iOS suspending the app — is addressed explicitly,
  since prompt 16 has an acceptance criterion for it.
- **AC-5.4** The cost of the chosen mechanism is estimated, so "polling is wasteful" is answered with
  a number.

### US-6 — Know whether it is working, from the first day

> As the **product owner**, I want generation time and match quality to be stored numbers on a
> backoffice screen the day fast mode ships, not a follow-up ticket.

**Acceptance criteria**

- **AC-6.1** §8 names the columns that carry both numbers and says why they are columns rather than
  logs or Redis counters.
- **AC-6.2** The backoffice list, its filters, and the dashboard panel's exact contents are specified,
  in the shape D-67's setlist.fm panel already established.
- **AC-6.3** Numeric investigate-thresholds are given for both numbers, so the panel means something
  the first time somebody looks at it.
- **AC-6.4** The report's storage shape is specified — codes and parameters, never rendered English —
  so prompt 16 renders it and prompt 15's design is not baked into the database.

---

## Technical Approach

### Component shape

```
backend/src/Service/Playlist/                    ← NEW, provider-agnostic, no provider symbol
  PlaylistPipeline.php              ← §3. The ordered stages. The ONE entry point both modes use
  Stage/
    PreflightStage.php              ← provider availability, account, quota posture, dedup
    SetlistSelectionStage.php       ← §D-131/D-132. Cached rows first; at most one page of budget
    MatchingStage.php               ← drives App\Service\Matching\TrackMatcher, per-song progress
    ReviewStage.php                 ← no-op in Fast mode; the CHOICE-band suspension in Normal
    CreationStage.php               ← createPlaylist() + the creation marker (D-136)
    InsertionStage.php              ← addTracks() in batches + the insertion watermark (D-137)
    ReportStage.php                 ← freezes counters, timings and report codes
  JobStateMachine.php               ← §1. The only class allowed to write PlaylistGenerationJob::$state
  JobProgressWriter.php             ← §7. The per-song counter update, its own transaction
  GenerationEstimator.php           ← §8's timings; the estimate the UI shows
  Model/
    JobState.php                    ← enum, 11 cases
    JobMode.php                     ← enum: Fast | Normal
    BlockedReason.php               ← enum, 6 cases
    FailureReason.php               ← enum, 3 cases
    ResultKind.php                  ← enum: Complete | Partial | NoTracksMatched | NoSourceMaterial
    ReportCode.php                  ← enum. Codes and parameters, never English (D-141)
    SelectedSetlist.php             ← band, setlist, selection reason, fingerprint
  Exception/
    SetlistBudgetExhaustedException.php
    NoSetlistsAvailableException.php
    PlaylistCreationIndeterminateException.php
    JobExpiredException.php
    GenerationBlockedException.php  ← carries BlockedReason + resumableAfter
  Naming/
    PlaylistNamer.php               ← D-140. Name and description from Concert + lineup

backend/src/Message/
  BuildPlaylistMessage.php          ← { jobId, attempt } and nothing else (D-125)

backend/src/MessageHandler/
  BuildPlaylistHandler.php          ← loads the job, locks it, calls PlaylistPipeline::run()

backend/src/Entity/
  Playlist.php · PlaylistTrack.php · PlaylistGenerationJob.php     ← NEW (§2)

backend/src/Command/
  ExpireSuspendedJobsCommand.php    ← app:playlist:expire-jobs, nightly (D-138)
  ResumeBlockedJobsCommand.php      ← app:playlist:resume-blocked, every 5 min (D-134)

backend/config/packages/messenger.yaml            ← the transport that does not exist yet (D-125)
```

`PlaylistPipeline` is the only public entry point, mirroring `SetlistGateway`'s single-door shape
(D-58) and `TrackMatcher`'s (spec 12), for the same reason: a rule is only as strong as its weakest
caller. **`JobStateMachine` is the only class permitted to assign `PlaylistGenerationJob::$state`** —
enforced the same structural way, by a static source scan (AC-8.4). Eleven states scattered across
seven stage classes is exactly how a state machine becomes untrue to its own diagram.

---

## 1. The job state machine

### The states

Eleven, in four classes. The classification matters more than the count: it is what tells prompt 16
whether to show a spinner, a "we'll pick this up at midnight" notice, or a result.

| State | Class | Meaning | Who moves it out |
|---|---|---|---|
| `queued` | **active** | The row exists, the message is dispatched, no work has begun | The worker |
| `resolving_setlist` | **active** | Choosing which setlist(s) this concert's playlist is built from | The worker |
| `awaiting_setlist_choice` | **suspended** | Normal mode only. Candidate setlists are persisted; the user has not chosen | The user, via `POST …/setlist-choice` |
| `matching` | **active** | Normalizing and resolving songs to tracks, one song at a time. **This is where 90 % of wall-clock time is spent** | The worker |
| `awaiting_version_choice` | **suspended** | Normal mode only, **and only when the CHOICE band is non-empty**. Ranked candidates are persisted; the user has not chosen | The user, via `POST …/version-choices` |
| `building` | **active** | The provider playlist exists (or is about to); tracks are being inserted in batches | The worker |
| `blocked` | **blocked** | Nothing is wrong. A precondition of the world is temporarily false. Everything computed so far is kept | `app:playlist:resume-blocked`, or a user action that clears the reason |
| `completed` | **terminal (success)** | A playlist exists with ≥ 1 track, **or** the source material genuinely had nothing to offer. Includes every partial result | — |
| `failed` | **terminal (retryable by the user)** | One of exactly three genuine failures (§4). A retry re-enters the *same row* | The user, via `POST …/retry` |
| `expired` | **terminal** | A suspended job outlived its TTL. Choices are kept for pre-fill; candidate lists are dropped | — |
| `cancelled` | **terminal** | The user abandoned it, or deleted the concert | — |

**`blocked` is not a failure and must never be rendered as one.** It carries `blockedReason` (one of
`setlistfm_budget`, `provider_quota`, `provider_rate_limit`, `provider_disabled`, `needs_reauth`,
`upstream_unavailable`), `resumableAfter` (a concrete instant, nullable when the unblock is a human
action), `blockedAtStage` and `blockCycleCount`. **D-126.**

### The transitions

Every legal edge. Nothing else is legal, and `JobStateMachine` rejects an illegal assignment with a
`\LogicException` — a bug, not a user-facing error.

| # | From | To | Trigger |
|---|---|---|---|
| T-01 | *(none)* | `queued` | `POST /api/playlist-generation-jobs` passes pre-flight; the row and the message are committed together (D-129) |
| T-02 | `queued` | `resolving_setlist` | The worker acquires the job lock and re-checks `ProviderRegistry::isAvailable()` |
| T-03 | `resolving_setlist` | `matching` | Fast mode, or Normal mode where the band has exactly one usable setlist (D-132's `only_one_available`) |
| T-04 | `resolving_setlist` | `awaiting_setlist_choice` | Normal mode with ≥ 2 candidate setlists |
| T-05 | `awaiting_setlist_choice` | `matching` | The user submits a choice; a fresh `BuildPlaylistMessage` is dispatched |
| T-06 | `matching` | `building` | Fast mode, or Normal mode with an empty CHOICE band, **and** ≥ 1 track resolved |
| T-07 | `matching` | `awaiting_version_choice` | Normal mode with a non-empty CHOICE band |
| T-08 | `awaiting_version_choice` | `building` | The user submits version choices; a fresh message is dispatched |
| T-09 | `matching` | `completed` | **Zero** tracks resolved. `resultKind = no_tracks_matched`. **No provider playlist is created** (D-135) |
| T-10 | `resolving_setlist` | `completed` | No band on the lineup has a usable setlist. `resultKind = no_source_material` |
| T-11 | `building` | `completed` | The last batch is confirmed inserted. `resultKind ∈ {complete, partial}` |
| T-12 | `resolving_setlist` \| `matching` \| `building` | `blocked` | Any F-01/F-03/F-04/F-05/F-06/F-12 condition (§4) |
| T-13 | `blocked` | `queued` | `resumableAfter` has passed and the reason re-tests clear, **or** the user re-linked / the operator re-enabled. `blockCycleCount++` |
| T-14 | `blocked` | `failed` | `blockCycleCount > MAX_BLOCK_CYCLES` (**3**) — the world is not coming back on its own |
| T-15 | `resolving_setlist` \| `matching` \| `building` | `failed` | F-08, F-13, or an unhandled `\Throwable` after Messenger's retries are spent |
| T-16 | `failed` | `queued` | The user retries. **Same row, same idempotency key, `attempt++`** — this is what makes §5 work |
| T-17 | `awaiting_setlist_choice` \| `awaiting_version_choice` | `expired` | `app:playlist:expire-jobs` finds it past its TTL (D-138) |
| T-18 | any non-terminal | `cancelled` | The user cancels, or the parent `Concert` is deleted |
| T-19 | `awaiting_*` | `blocked` | The resume attempt finds the provider disabled or the account `needs_reauth` — a suspended job is not a dead end (AC-4.4) |
| T-20 | `queued` | `queued` | Messenger redelivery. **Idempotent by design**: the handler's first act is to take the lock and re-read the state |

Illegal by construction and worth naming: `completed → anything` (a finished playlist is a fact; a
regeneration is a **new job**), `building → matching` (matching results are frozen when `building`
is entered — otherwise the watermark means nothing), and `expired → queued` (an expired job pre-fills
a new one; it does not resurrect).

### What is persisted, and when

The rule: **every state entry is its own committed transaction, and no provider call happens inside
an open transaction.** A worker killed at any instant leaves a row whose state is true.

| On entering | Committed, in one transaction |
|---|---|
| `queued` | The job row: owner, concert, provider key, `streamingAccountId`, mode, `algorithmVersion`, `idempotencyKey`, `attempt`, `createdAt`. Then the Messenger message (D-129 — same transaction, via the doctrine transport's outbox behaviour, so a committed job is always a dispatched job) |
| `resolving_setlist` | `startedAt`, `stageEnteredAt` |
| `awaiting_setlist_choice` | `candidateSetlists jsonb` (setlistfmId, date, venue, tour, songCount per candidate — from cached rows, no extra calls), `suspendedAt`, `expiresAt` |
| `matching` | `selectedSetlists jsonb` (per band: setlistfmId, `selectionReason`, `setlistFingerprint`), `songsTotal`, the ordered `PlaylistTrack` skeleton rows — **one row per source song, created up front with `outcome = pending`** (D-139) |
| *(per song, inside `matching`)* | `songsProcessed++` and that song's `PlaylistTrack` row: `providerTrackId`, `confidence`, `outcome`, `reasonCode`. **One small transaction per song** (§7) |
| `awaiting_version_choice` | `pendingChoices jsonb` (per ambiguous song: the top 3–5 `TrackCandidate`s and their sub-scores), `suspendedAt`, `expiresAt` |
| `building` | `matchingFinishedAt`, the frozen counters, and — immediately before the provider call — `Playlist.creationAttemptedAt` (D-136) |
| *(per batch, inside `building`)* | `Playlist.insertedThroughOrdinal` and the batch's `PlaylistTrack.insertedAt` values. **One transaction per provider call** (D-137) |
| `blocked` | `blockedReason`, `resumableAfter`, `blockedAtStage`, `blockCycleCount++`. **Nothing computed is discarded** |
| `completed` | `finishedAt`, `durationMs`, `stageTimings jsonb`, the six outcome counters, `meanConfidence`, `resultKind`, `reportCodes jsonb` |
| `failed` | `finishedAt`, `failureReason`, `failureDetail` (a code and parameters, never a stack trace) |

---

## 2. The entities

Consistent with `docs/architecture.md` §10, which already sketches `Playlist ──< PlaylistTrack` and
names `PlaylistTrack.outcome` as "what makes the report possible". This section fills that sketch in
and adds the job. **D-127.**

**Identity correction (D-146, resolved on prompt 14's approval, 2026-08-23):** the sketches below
originally wrote `id uuid` for all three entities. Prompt 14 implements them with the project's
existing integer surrogate identity instead — `#[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column(type:
'integer')]`, the same convention every other entity in `backend/src/Entity/` uses. Introducing a
second identity convention for four tables would fork the locator shape, the URL patterns and a
`ramsey/uuid`-shaped dependency question for no behavioural gain; `Concert` is already an enumerable
integer id behind `ConcertOwnerExtension`'s cross-owner 404, and these resources copy that gate
exactly. The one place this spike leaned on a UUID — F-14's "find the possible orphan in your
account" marker — is served just as well by `Setlistify job #<id>` in the playlist description. Read
every `id` below as `integer`, not `uuid`.

```
PlaylistGenerationJob
  id                     integer (SERIAL)
  owner                  FK User            ← user-scoped: 404 not 403 (D-143)
  concert                FK Concert
  providerKey            string(32)         ← StreamingProviderInterface::key(), a runtime string
  streamingAccountId     FK StreamingAccount
  mode                   enum               ← fast | normal
  state                  enum               ← §1's eleven
  idempotencyKey         string(64)         ← §5. sha256 hex
  attempt                smallint           ← incremented by T-16, never by Messenger redelivery
  algorithmVersion       smallint           ← copied from the matching profile at queue time (D-121)

  candidateSetlists      jsonb null         ← suspension payload, Normal mode
  selectedSetlists       jsonb              ← [{ bandId, setlistfmId, selectionReason, fingerprint }]
  pendingChoices         jsonb null         ← suspension payload, Normal mode
  userChoices            jsonb null         ← kept through expiry, for pre-filling a new job

  songsTotal             int
  songsProcessed         int                ← the polling counter (§7)
  currentStage           enum
  stageEnteredAt         timestamptz

  blockedReason          enum null
  resumableAfter         timestamptz null
  blockedAtStage         enum null
  blockCycleCount        smallint
  failureReason          enum null
  failureDetail          jsonb null
  resultKind             enum null

  matchedCount           int   ─┐
  lowConfidenceCount     int    │
  notFoundCount          int    ├─ §8's match-quality numbers, frozen at completion
  skippedCount           int    │
  regionRestrictedCount  int    │
  meanConfidence         real  ─┘
  durationMs             int null            ← §8's generation-time number
  stageTimings           jsonb null          ← { preflight: ms, selection: ms, matching: ms, … }

  createdAt · startedAt · finishedAt · suspendedAt · expiresAt   timestamptz

  UNIQUE (concert_id, provider_key) WHERE state IN
      ('queued','resolving_setlist','awaiting_setlist_choice','matching',
       'awaiting_version_choice','building','blocked')          ← partial unique index (D-129)
  INDEX (state, resumable_after)                                ← the resume sweeper's query
  INDEX (state, expires_at)                                     ← the expiry sweeper's query

Playlist
  id                     integer (SERIAL)
  owner                  FK User
  concert                FK Concert
  job                    FK PlaylistGenerationJob (the job that produced it)
  providerKey            string(32)
  providerPlaylistId     string null         ← the CREATION MARKER (D-136). NULL until confirmed
  creationAttemptedAt    timestamptz null    ← written BEFORE createPlaylist() (D-136)
  externalUrl            string null
  name                   string(200)
  description            text null
  insertedThroughOrdinal int                 ← the INSERTION WATERMARK (D-137)
  reportSummary          jsonb               ← job-level report codes + parameters (D-141)
  createdAt · updatedAt        ← updatedAt is an addition over this spike's original sketch (D-150,
                                  resolved on prompt 14's approval): it exists solely to make the
                                  polling `ETag` cheap to compute, and applies to PlaylistGenerationJob
                                  as well (its own createdAt/updatedAt pair, listed in §2's job sketch)

PlaylistTrack
  id                     integer (SERIAL)
  playlist               FK Playlist
  ordinal                int                 ← position in the PLAYLIST, dense, 0-based
  sourceSong             FK Song
  sourceBand             FK Band             ← denormalized for multi-band ordering and the report
  sourcePosition         int                 ← Song::$position, preserved even when unmatched (D-140)
  segmentIndex           smallint null       ← medley segments (prompt 12, D-114)
  providerTrackId        string null
  confidence             real null
  outcome                enum                ← pending | matched | matched_low_confidence
                                               | skipped | not_found | region_restricted
  reasonCode             enum null           ← the report's per-song code (D-141)
  insertedAt             timestamptz null    ← NULL until its batch is confirmed (D-137)

  UNIQUE (playlist_id, ordinal)
  INDEX  (playlist_id, source_position)
```

**Every song in the source setlist gets a `PlaylistTrack` row, including the ones that produce no
track** — `docs/architecture.md` §10 states this, spec 12 §5 depends on it, and D-139 makes it the
mechanism by which partial success is storable at all. `ordinal` is the dense position among
*inserted* tracks; `sourcePosition` is the position in the show. They diverge exactly when something
was missed, and that divergence is the report.

---

## 3. The pipeline: one path, two modes

```
                                       FAST                      NORMAL
  ┌──────────────────────────────────────────────────────────────────────────────┐
  │ PREFLIGHT      provider enabled? account connected? no live job? concert own? │  shared
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ SELECT         most recent substantial (D-132)  │  ► SUSPEND: user chooses    │  DECISION POINT 1
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ NORMALIZE      SongNormalizer over every source Song (prompt 12 §1)           │  shared
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ MATCH          TrackMatcher per song, cache-first (prompt 12 §2, §8)          │  shared
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ REVIEW         CHOICE band: include + flag     │  ► SUSPEND: user picks       │  DECISION POINT 2
  │                (spec 12, resolved Q1)          │    (only if band non-empty)  │
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ CREATE         createPlaylist() — LAST irreversible step (D-135)              │  shared
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ INSERT         addTracks() in batches, watermarked (D-137)                    │  shared
  ├──────────────────────────────────────────────────────────────────────────────┤
  │ REPORT         freeze counters, timings, report codes                         │  shared
  └──────────────────────────────────────────────────────────────────────────────┘
```

**The two modes differ at exactly two `if` statements — and nowhere else. D-130.** Prompt 17's brief
says that if Normal mode "starts to feel like a parallel implementation, stop and revisit prompt 13".
This is what stops that: the mode is a column, the suspension points are guards inside
`SetlistSelectionStage` and `ReviewStage`, and every other stage is literally the same object graph
executing the same code.

**How "one pipeline" is made testable rather than asserted (AC-4.2).** Prompt 17's acceptance
criterion demands a test, so this design supplies the property the test asserts: *a Normal-mode job
on a band with exactly one usable setlist and an empty CHOICE band must produce a state sequence
identical to the Fast-mode job for the same concert, and an identical set of `PlaylistTrack` rows.*
That is a single integration test over the test-double adapter, and it fails the moment somebody
forks the pipeline.

### Match everything first, create the playlist last — D-135

The order above puts `CREATE` after `MATCH`, which is not the obvious order. It is deliberate, and it
is the decision that makes four other things fall out for free:

1. **A quota-exhausted provider never leaves an empty playlist in the user's account.** The first
   `searchTrack()` throws `QuotaExhaustedException` at song 1, long before anything exists on the
   provider side. This is the "refuse upfront" behaviour prompt 13 asks about (§4, F-03) obtained
   *without* a tenth port method for querying remaining quota — the adapter's own accounting already
   raises the typed exception, and the pipeline simply arranges to hear it early.
2. **`no_source_material` and `no_tracks_matched` create nothing.** T-09 and T-10 reach `completed`
   with no provider playlist, so a band with no setlist.fm data does not litter someone's Spotify
   with an empty list named after a concert.
3. **The irreversible step is entered once we know it is worth entering** — which shrinks the one
   idempotency gap that cannot be fully closed (D-136) to the narrowest slice of the run.
4. **The expensive, failure-prone phase is fully restartable.** Matching writes only our own rows;
   a crash mid-match costs nothing but CPU, because `TrackResolution` (D-121) has already banked
   every provider call the attempt spent.

The cost is that the user's provider account shows nothing until ~90 % of the wall-clock time has
elapsed. That is irrelevant: they are watching our progress screen (§7), not Spotify's library, and
prompt 15's four result variants are all rendered from our data.

---

## 4. The failure taxonomy

Prompt 13 lists twelve failure modes and requires a decided behaviour for each. All twelve are here,
plus four this design discovered while writing the state machine. **D-134.**

Reading the table: **"Lands in"** is the `PlaylistGenerationJob.state`; **"Retry"** says whether
recovery is automatic (a sweeper), manual (a user action), or none.

| # | Failure mode | Detected by | Typed error | Lands in | Retry | User-facing behaviour |
|---|---|---|---|---|---|---|
| **F-01** | **setlist.fm daily budget exhausted** | `CachedFetch::$reason === 'budget_exhausted'` **and** no usable cached index for the band | `SetlistBudgetExhaustedException` | **`blocked`**, `setlistfm_budget`, `resumableAfter = $fetch->budgetResetAt` | **Automatic**, at the UTC reset instant prompt 09 already computes | *"We've used today's setlist.fm allowance. We'll finish this at 00:00 UTC — nothing is lost."* Never an error screen |
| **F-02** | **Band unknown to setlist.fm** | `BandIdentityResolver` returns `no_presence` or `ambiguous` | — (not an exception) | **`completed`**, `resultKind = no_source_material` *(or partial, on a multi-band bill)* | None needed | *"We couldn't find <Band> on setlist.fm."* On a multi-band concert the other bands still produce a playlist |
| **F-03** | **Band known but no songs recorded** | Every candidate `Setlist::$isEmpty` or `songCount === 0` | — | **`completed`**, `no_source_material` | None | *"Nobody has posted a setlist for this show yet."* Explicitly a success state — this is prompt 14's AC-2 |
| **F-04** | **Provider quota exhausted mid-run** | `QuotaExhaustedException` from `searchTrack()` or `addTracks()` | re-thrown as `GenerationBlockedException(provider_quota)` | **`blocked`**, `resumableAfter` = the provider's next window start (next 00:00 America/Los_Angeles for YouTube; adapter-declared) | **Automatic** | *"<Provider> has hit its daily limit. We'll finish this tomorrow."* Everything matched so far is kept; a resumed run re-resolves nothing (D-121) |
| **F-05** | **Provider rate limit** | `RateLimitedException`, carrying `retryAfterSeconds` | — | **stays active** for up to `RATE_LIMIT_INLINE_RETRIES` (**3**), sleeping `retryAfterSeconds` (capped at 30 s); then **`blocked`**, `provider_rate_limit`, `resumableAfter = now + 15 min` | **Automatic** | Invisible in the normal case — the progress bar simply pauses. Only a persistent limit reaches the UI |
| **F-06** | **OAuth token expired mid-run** | `TokenExpiredException` from `StreamingTokenManager::usableTokens()`. D-80 has **already** flipped the account to `needs_reauth` | — | **`blocked`**, `needs_reauth`, `resumableAfter = null` | **Manual** — the job re-queues when the `StreamingAccount` returns to `connected` | *"Your <Provider> connection expired."* with the re-link path prompt 16 has an AC for. After re-linking, one tap resumes — it does not restart |
| **F-07** | **Provider disabled mid-run via `ProviderRegistry`** (prompt 11) | `ProviderRegistry::isAvailable()` re-checked **at every stage boundary**, and `ProviderDisabledException` from `StreamingTokenManager` | `ProviderDisabledException` *(already exists)* | **`blocked`**, `provider_disabled`, `resumableAfter = now + 30 min` (the registry's Redis cache TTL plus slack) | **Automatic** on re-enable | *"<Provider> is temporarily unavailable."* — the exact wording prompt 11 already designed (D-94). Never a 500, never a crash |
| **F-08** | **Provider playlist created but track insertion fails partway** | `insertedThroughOrdinal < count(tracks)` when a batch throws | whichever of F-04/F-05/F-12 caused it | **`blocked`** (never `failed`) with the causing reason; `building` is re-entered on resume | **Automatic**, from the watermark | The playlist **exists and is playable** with what got in. The UI says *"still adding the last N songs"*. This is a partial success in progress, not a broken playlist |
| **F-09** | **Song absent from the catalog** | Zero candidates, or best `conf < 0.55` (prompt 12 §3) | — | no state change | n/a | Per-track `outcome = not_found`, report code `TRACK_NOT_IN_CATALOG`. **Not a failure of anything** |
| **F-10** | **Only live / cover versions available** | Every candidate carries a Version qualifier (prompt 12 §4) | — | no state change | n/a | The best live recording is used at full confidence; report code `LIVE_VERSION_ONLY`. Covers use the original artist and report `COVER_OF` with the artist name (spec 12, resolved Q3) |
| **F-11** | **Region-restricted track** | `RegionRestrictedException` at insert time | *(already exists)* | no state change | n/a | Per-track `outcome = region_restricted`, report code `NOT_AVAILABLE_IN_REGION`. **The `TrackResolution` row is NOT invalidated** — it is still correct for everyone else (prompt 12 §8) |
| **F-12** | **Network / transport / provider 5xx** | `ProviderUnavailableException`, or a transport-level `\Throwable` | — | Messenger retries **3×** (5 s / 30 s / 180 s, 20 % jitter); then **`blocked`**, `upstream_unavailable`, `resumableAfter = now + 15 min`; after `MAX_BLOCK_CYCLES` (3) → **`failed`** | Automatic, then manual | Invisible until the third block cycle, then *"we're having trouble reaching <Provider> — try again later"* |
| **F-13** | **Track id vanished at insert** | `NotFoundException` at `addTracks()` | *(already exists)* | no state change | n/a | Per-track `not_found`; **the `TrackResolution` row is deleted** — prompt 12 §8's one *required* runtime invalidation. Deleted YouTube videos are routine |
| **F-14** | **Playlist creation outcome indeterminate** | On entry to `building`: `creationAttemptedAt != null` **and** `providerPlaylistId == null` | `PlaylistCreationIndeterminateException` | **`failed`**, `failureReason = creation_indeterminate` | **Explicit user action only** | The one honest gap (D-136): *"We may have created an empty playlist called '<name>' in your account. We won't create another unless you tell us to."* with a *"create it anyway"* action |
| **F-15** | **Provider key has no adapter** | `UnknownProviderException` from `StreamingProviderLocator` | *(already exists)* | **`failed`**, `unknown_provider` | None | A deployment/config defect, not a user situation. Terminal, alerted, never auto-retried |
| **F-16** | **Suspended job outlived its TTL** | `app:playlist:expire-jobs` | `JobExpiredException` | **`expired`** | New job, pre-filled from `userChoices` | *"This selection expired — pick up where you left off?"* A path forward, never a dead end (prompt 17's AC) |

**The three — and only three — routes to `failed`** are F-14 (indeterminate creation), F-15 (unknown
provider) and a block cycle count exceeding 3 (T-14). Everything else is `completed`, `blocked` or a
per-track outcome. That is what "degrades, does not fail" means when it is written as a state
machine rather than as an aspiration.

### setlist.fm budget: never spend it speculatively — D-131

Generation reads **cached rows first**. `SetlistNormalizer::hydrateSetlistsPage()` shares
`hydrateOne()` with the detail endpoint, so an already-cached index page carries full `Song` rows —
selection and matching over a cached band cost **zero setlist.fm calls**. Budget is spent only when a
band's index has never been fetched, and then:

- **at most `GENERATION_SETLIST_PAGES = 1` page (20 entries) per band per generation**, which is the
  same bound `BandSetlistsProvider::backfillUntilCovered()` already respects;
- **never** a per-setlist detail fetch — the index payload is sufficient, so a 4-band festival costs
  at most 4 calls of a 1,440/day budget, not 4 × 20;
- if the budget is spent and *some* cache exists, generation proceeds on cached data with report code
  `SETLIST_MAY_BE_STALE` (the D-63 freshness posture, applied to a job instead of a response);
- if the budget is spent and *nothing* is cached, F-01 blocks with `budgetResetAt`.

No generation ever triggers the nightly refresh job or a "check for anything newer" read (D-65's
rule, applied here).

### Provider quota: refuse upfront **and** stop cleanly — D-135's other half

Prompt 13 asks for one or the other. The answer is that D-135's stage ordering makes them the same
mechanism:

- **Upfront in effect.** The first provider call of a run is `searchTrack()` for song 1, before any
  playlist exists. An adapter whose Redis unit counter says the run cannot afford to start raises
  `QuotaExhaustedException` there, and the job blocks having created nothing. The user sees *"not
  enough YouTube budget left today — we'll do this tomorrow"* rather than a half-built artifact.
- **Cleanly partway** for the case that upfront checking can never cover: another user's generation
  consumed the remaining units while this one was running. The exception arrives at song 12 or at
  batch 2, the watermark holds, the job blocks, and the resumed run continues from song 12 with every
  earlier resolution already cached.
- **The pipeline holds no unit arithmetic.** Cost per call is provider-shaped (100 units per YouTube
  search, 50 per insert; Spotify counts requests, not units) and therefore lives inside the adapter,
  which is where prompt 18's brief already puts it. `App\Service\Playlist\` never learns what a unit
  is — which is why F-04 works identically for a provider that has no quota concept at all.

---

## 5. Idempotency: a retry cannot duplicate

Three mechanisms, at three levels. Each closes a distinct duplication route, and the residual is
stated rather than hidden. **D-136**, **D-137**.

### Level 1 — one live job per (concert, provider): the partial unique index

```sql
CREATE UNIQUE INDEX uniq_live_generation
  ON playlist_generation_job (concert_id, provider_key)
  WHERE state IN ('queued','resolving_setlist','awaiting_setlist_choice',
                  'matching','awaiting_version_choice','building','blocked');
```

A double-tapped button, a retried HTTP request, two devices — all collide on the database, not on a
service-layer check with a race in it. `POST /api/playlist-generation-jobs` catches the violation and
returns **the existing job, 200**, not a second job and not a 409. Starting a generation is
idempotent from the client's point of view, which is precisely what an unreliable mobile network
needs. **D-129.**

The `idempotencyKey` column — `sha256(concertId | providerKey | mode | algorithmVersion |
sourceFingerprint)`, where `sourceFingerprint` hashes the ordered `(setlistfmId, songCount)` pairs
chosen plus any user choices — is *not* the uniqueness mechanism (the index above is). It is the
**equality mechanism**: it tells a resumed or retried run whether it is the same generation, and it
is what T-16 preserves so a retry re-enters the same row rather than forking one.

### Level 2 — the creation marker: never a second provider playlist

```
   1. COMMIT  Playlist.creationAttemptedAt = now          ← before any network call
   2. CALL    StreamingProviderInterface::createPlaylist(PlaylistDraft, ProviderTokens)
   3. COMMIT  Playlist.providerPlaylistId = <id>, externalUrl = <url>
```

On entering `building`, the pipeline branches on the pair:

| `creationAttemptedAt` | `providerPlaylistId` | Meaning | Action |
|---|---|---|---|
| `NULL` | `NULL` | Never attempted | Create |
| set | set | Created and confirmed | **Skip creation, reuse the id** — the ordinary retry path |
| set | `NULL` | **Indeterminate** — the call may have succeeded before the process died | **F-14: stop. Do not create.** |

**The indeterminate case is the one gap this design cannot close, and it says so rather than
pretending otherwise.** Closing it would need a tenth port method — `findPlaylistByName()` or
`listPlaylists()` — and D-71 freezes the interface at nine. The alternatives were weighed:

- *Create anyway* — silently duplicates in exactly the situation the user is least likely to notice,
  and directly violates prompt 14's AC-6. Rejected.
- *Add a port method* — one method, on every future adapter, to serve a failure window measured in
  milliseconds. It also drags a provider-side query into the port's contract for no other caller.
  Rejected as a bad trade against D-71, and recorded here as the concrete price of that freeze.
- *Ask the user* — chosen. The playlist name is deterministic (`PlaylistNamer`, D-140) and the
  description carries `Setlistify job <shortId>`, so the user can find and identify the possible
  orphan in two taps. The job lands in `failed` with a *"create it anyway"* action that clears
  `creationAttemptedAt` and re-queues.

The window is the interval between the provider accepting the create and our commit landing —
sub-second, and reachable only by a worker kill or a database outage in that instant. Making it
**visible and user-resolvable** is a better answer than making it invisible and occasionally wrong.

### Level 3 — the insertion watermark: never a duplicated track

`Playlist.insertedThroughOrdinal` is the count of `PlaylistTrack` rows confirmed inserted at the
provider, and it advances **only after** the provider call returns:

```
   batch = tracks[insertedThroughOrdinal … insertedThroughOrdinal + INSERT_BATCH_SIZE)
   CALL   addTracks(providerPlaylistId, batch.trackIds, tokens)
   COMMIT insertedThroughOrdinal += count(batch); batch rows' insertedAt = now
   repeat
```

`INSERT_BATCH_SIZE = min(50, provider maximum)` — Spotify accepts 100 ids per request, YouTube
inserts one item per call at 50 units each, and the adapter is free to fan a batch out internally.
A resumed run starts at the watermark, so an earlier batch is never re-sent.

**The residual window is exactly one provider call wide**, and it is the same shape as level 2: a
batch accepted by the provider whose commit did not land. On YouTube, where a call carries one track,
that is one possible duplicate track; on Spotify, up to one batch. On resume, if
`attempt > 1 && insertedThroughOrdinal > 0`, the job sets report code
`RESUMED_MID_INSERTION` so the report tells the truth — *"we resumed this playlist; a track near
position N may appear twice"* — rather than the user discovering it themselves. Prompt 18 may shrink
this to zero for YouTube by using its per-request client token; that is an adapter-local
optimization, not a pipeline concern.

**Safe retry boundaries, stated plainly.** A retry is safe from the start of any stage, because every
stage is either purely local (`SELECT`, `NORMALIZE`, `MATCH`, `REPORT` — all writing only our own
rows, all backed by `TrackResolution` so no provider call is re-spent) or watermarked (`CREATE`,
`INSERT`). There is no stage from which a retry is unsafe, and no partial in-memory state a retry
depends on.

---

## 6. Suspend and resume (Normal mode)

Prompt 17 implements this; the design exists here so that prompt 14 does not build a schema that
precludes it. **D-138.**

### The two suspension points

| Point | State | Persisted | Provider cost already spent |
|---|---|---|---|
| **Setlist choice** | `awaiting_setlist_choice` | `candidateSetlists jsonb` — up to 20 cached setlists with date, venue, city, tour, `songCount` | **None.** Read entirely from cached `Setlist` rows |
| **Version choice** | `awaiting_version_choice` | `pendingChoices jsonb` — per ambiguous song, the top 3–5 `TrackCandidate`s with their sub-scores | **All of it.** Matching has already run; this suspension holds the *results* of provider calls |

That asymmetry is why the two TTLs differ:

| | TTL | Reason |
|---|---|---|
| `awaiting_setlist_choice` | **7 days** | Costs nothing to hold. A user browsing setlists over a weekend is normal behaviour |
| `awaiting_version_choice` | **72 hours** | Holds candidate lists that decay: catalogs change, YouTube videos are deleted, and prompt 12's `matched_low_confidence` resolutions carry a 60-day TTL that these snapshots are *not* covered by. Three days is long enough for a real human interruption and short enough that the candidates are still true |

`app:playlist:expire-jobs` runs nightly (the same cron shape as `app:setlist:refresh`, D-65), moves
expired jobs to `expired`, **keeps `userChoices`**, and **drops `candidateSetlists` / `pendingChoices`**
— which is also the answer to prompt 13's data-growth risk: the large JSONB payloads have a hard
72-hour and 7-day lifetime, while the row that remains is a few hundred bytes.

### Staleness on resume — every case decided

The world moves while a job sleeps. None of these fails the job.

| What changed | Detected by | Behaviour |
|---|---|---|
| **The setlist was corrected on setlist.fm** | `setlistFingerprint` (sha256 of the ordered song titles) recomputed on resume | Re-match **only** the songs whose titles changed; keep every user choice whose song is unchanged. Report code `SETLIST_CORRECTED_SINCE_SELECTION` |
| **`algorithmVersion` was bumped** | Job's stored version ≠ current profile version | **Keep the user's explicit choices** — a human decision outranks a formula — and re-score only the songs still unanswered. Report code `RESCORED_AFTER_ALGORITHM_UPDATE` |
| **A chosen candidate no longer exists** | `NotFoundException` at insert | F-13: per-track `not_found`, `TrackResolution` deleted. The rest of the playlist is unaffected |
| **The provider was disabled, or the token expired** | Pre-flight on resume | **T-19: `blocked`, not `failed`.** The suspension's payload is retained; the user re-links and continues |
| **The concert was deleted** | FK cascade | T-18: `cancelled` |
| **A candidate setlist was purged from the cache** | Row missing on resume | Re-read from cached rows; if the specific chosen setlist is gone, fall back to D-132's automatic rule and report `SELECTED_SETLIST_UNAVAILABLE` |

---

## 7. Progress: polling, and why — D-128

**Recommendation: HTTP polling of the job resource, every 1.5 seconds while the job is active, with a
server-supplied `Retry-After` the client honours.** Rejected: SSE and WebSockets. The reasoning is
about Expo specifically, because prompt 13 and prompt 16 both flag it as a genuine constraint.

| Option | Verdict | Reasoning |
|---|---|---|
| **Polling** | **Recommended** | One code path across web, iOS and Android. Stateless, so a dropped connection is *not an event* — the next poll simply succeeds. Survives app backgrounding trivially: the client stops polling when the screen loses focus and resumes on return, and the server-side job carried on regardless. Needs no infrastructure that does not already exist |
| **Server-Sent Events** | Rejected | React Native ships no `EventSource`; the polyfills route through XHR streaming and behave differently across Hermes versions and across iOS/Android. Worse, iOS suspends network connections when the app backgrounds — so the "leave the screen and come back" case prompt 16 has an acceptance criterion for would need a *reconnect-and-catch-up* path, which is polling with extra steps |
| **WebSockets / Mercure** | Rejected | A second server component, a second auth path, a second thing to operate, for a wait measured in tens of seconds. This is the "general-purpose workflow engine" instinct prompt 13 warns about, applied to transport |
| **Push notification on completion** | Deferred, deliberately | Prompt 16 raises it for generations over ~30 s. It needs Expo push credentials and a notification permission flow — a feature, not a progress mechanism. Noted, not built |

**The cost, so "polling is wasteful" is answered with a number.** A 25-song generation is ~30 s ≈ **20
polls**. Each poll is one indexed primary-key read of one row (the progress fields are columns on the
job — §8) served with an `ETag`, so a poll that finds no change is a **304** with no body. Twenty 304s
per generation is not a load problem at any volume this product will see before it has a platform
team to reconsider it.

**The shape.** `GET /api/playlist-generation-jobs/{id}` returns `state`, `currentStage`,
`songsProcessed`, `songsTotal`, `estimatedSecondsRemaining` (from `GenerationEstimator`, using the
rolling p50 per-song time), `blockedReason`, `resumableAfter`, and — once terminal — the playlist id.
`Retry-After` is **1** while `matching` or `building`, **3** while `queued` or `resolving_setlist`,
and **absent** on a terminal or suspended state, which is how the client knows to stop polling
without special-casing every state name.

**Per-song progress is a column, updated per song, in its own small transaction.** Twenty-five short
`UPDATE`s spread over six-plus seconds is negligible, and it buys three things a Redis counter would
not: the progress survives a worker crash, the backoffice can see where a stuck job stopped, and
there is exactly one source of truth for job state. Rejected: mirroring progress into Redis for
"faster" polls — a second source of truth for a read that is already a single indexed row.

---

## 8. The two numbers, and where they live — D-141, D-142

Prompt 14's risk note is unambiguous: *"Generation time and match quality are the two numbers that
matter — measure both from the first day and put them in the backoffice."* This design takes that
literally: both are **columns on `PlaylistGenerationJob`**, written at completion, and both are on a
backoffice screen that ships **with prompt 14**, not with a later prompt.

### Generation time

`startedAt`, `finishedAt`, `durationMs`, and `stageTimings jsonb` —
`{preflight, selection, normalize, matching, create, insert, report}` in milliseconds. Per-stage
matters because the interesting question is never "was it slow" but "was it slow in matching (the
provider is degraded, or the resolution cache is cold) or in insertion (the provider is throttling)".
Blocked intervals are excluded from `durationMs` and accumulated separately in `blockedMs`, so a job
that waited nine hours for a budget reset does not poison the p95.

### Match quality

`songsTotal`, `matchedCount`, `lowConfidenceCount`, `notFoundCount`, `skippedCount`,
`regionRestrictedCount`, `meanConfidence`, and `algorithmVersion`. The headline ratio is the
**match rate** = `(matched + lowConfidence) / (songsTotal − skipped)`, using spec 12 §9's definition
of *matchable* — a drum solo is neither a hit nor a miss. `algorithmVersion` on the row is what makes
a before/after comparison possible after a matching change, in exactly the way spec 12's fixture
harness does offline.

**Why columns rather than logs or a metrics backend.** The same argument prompt 12 made for storing
resolutions relationally (D-121): these are queryable product data, not telemetry. The backoffice
aggregates them in SQL, the fixture harness cross-references them, and there is no observability
stack in this project to send them to. A number in a log line is a number nobody will have when they
need it.

### The backoffice, shipped with prompt 14

Read-only, in the shape D-46 made the structural default and D-67's setlist.fm panel already
established.

- **`PlaylistGenerationJobCrudController`** — list: created, user (via `MaskedEmailField`, D-51),
  concert, provider, mode, **state**, duration, matched/total, `algorithmVersion`. Filters on state,
  provider, mode, `blockedReason` and `failureReason`. Detail view: the full `PlaylistTrack` table
  with per-song outcome, confidence and reason code, plus the block/failure detail and the stage
  timings.
- **`PlaylistCrudController`** — read-only list of generated playlists, with the report summary.
- **Dashboard panel "Playlist generation (last 7 days)"**: jobs started / completed / blocked /
  failed; **p50 and p95 generation time**; **mean match rate**; the `not_found` rate; the breakdown of
  `blockedReason`; and — the one line that actually tells an operator what to fix — the **five most
  frequently unmatched `(artist, title)` pairs**.
- **No write actions.** Not even a retry button: retry belongs to the user, whose account the playlist
  lives in. If an operator action is ever needed it goes through `AuditLogger` like every other admin
  write, but prompt 14 adds none.

**Investigate-thresholds, so the panel means something on first sight:** p95 generation time
**> 90 s**, or a 7-day match rate **< 0.75**, or a `blocked` share **> 10 %** of jobs. These are
initial figures on the same footing as spec 12's thresholds — guesses until there is traffic, to be
re-recorded once there is.

### The report's storage shape — D-141

The report is **stored, not computed at read time**, and it is stored as **codes and parameters,
never rendered English**:

```json
{ "code": "COVER_OF", "params": { "artist": "Nine Inch Nails" } }
{ "code": "TRACK_NOT_IN_CATALOG", "params": {} }
{ "code": "BANDS_OMITTED_FOR_LENGTH", "params": { "bands": ["…", "…"] } }
```

Per-song codes live on `PlaylistTrack.reasonCode`; job-level codes live in `Playlist.reportSummary`.
Prompt 15 designs the sentences and prompt 16 renders them, so the wording can change — and be
translated — without a migration, and prompt 15's design is never baked into the database.

---

## 9. Setlist selection: "most recent **substantial**" — D-132

Prompt 14 flags the problem exactly: *"a band's latest entry may be a three-song festival slot."* It
is not a hypothetical — a band's most recent setlist.fm entry in July is very often a festival
appearance, and building a concert playlist from it produces a four-track playlist for a
twenty-two-song show.

**The rule.** Over the band's most recent `SELECTION_WINDOW = 20` non-empty cached setlists, ordered
by `eventDate` descending:

```
median      = median songCount over the most recent 10 non-empty setlists
threshold   = max(SUBSTANTIAL_FLOOR, ceil(SUBSTANTIAL_RATIO × median))
              SUBSTANTIAL_FLOOR = 8        SUBSTANTIAL_RATIO = 0.60

pick        = the FIRST setlist in the window with songCount ≥ threshold
              whose eventDate is within RECENCY_LIMIT = 24 months of today
fallback 1  = the setlist with the highest songCount in the window        → 'fallback_longest'
fallback 2  = no non-empty setlists in the window                         → F-02/F-03
```

**Why relative-to-median rather than a flat floor.** A flat floor of 8 is wrong for exactly the bands
that need this most: a support act whose full set *is* eight songs would never qualify, and the rule
would silently fall back every time. The median-relative form asks the right question — *"is this a
real set for this band, or a truncated one?"* — and the floor exists only to stop a band with a
median of 4 from producing a threshold of 3, which would defeat the purpose.

**Why 0.60.** A festival slot is typically 25–40 % of a headline set (4–8 songs against 18–22). A
support slot is 40–55 %. A shortened or curfewed headline set is 75–90 %. 0.60 sits in the gap
between "a different kind of show" and "a slightly short version of this band's show", and it is the
first number this document expects to revise once there is real data — recorded here as a
calibration constant in `config/matching/`-adjacent configuration, on the same footing and for the
same reason as spec 12's thresholds (resolved Q2).

**Why a 24-month recency limit.** Without it, a band that has played only festivals for two years
would have its 2019 arena tour chosen — a *more substantial* setlist that is nonetheless not the show
the user attended. Past 24 months, recency wins and the report says so.

**What the user is told, always.** `selectionReason ∈ {most_recent_substantial, fallback_longest,
only_one_available, user_chosen}` is persisted per band and rendered in the report:
*"Built from the Barcelona show on 12 July 2023 — 24 songs."* The user can always see which night
they got, and Normal mode (prompt 17) is the override. **A default this opinionated must be
visible.**

---

## 10. Multi-band concerts — D-133

Prompt 14 is forbidden from improvising this, so it is decided here in full.

**One playlist per concert, containing every qualifying band, in stage order.**

### Why one, not several

A `Concert` is *one night*. The product's promise is "replay the show you went to", and a night is
one listening artifact. Three playlists for a three-band bill would multiply the entities the user
manages, the provider playlists cluttering their library, the jobs to poll, and the reports to read —
and it would make prompt 19's concert-page playback surface choose one of them arbitrarily.
`docs/architecture.md` §10 already models `Concert ──< Playlist`, and one row per concert per
provider is what D-129's partial unique index assumes.

### The order: stage order, which is billing order **reversed**

`ConcertBand::$billingOrder` is 0-based with **index 0 = headliner** (D-25). At an actual show the
headliner plays **last**. A playlist that replays the night must therefore iterate
**`ORDER BY billingOrder DESC`** — earliest support act first, headliner's setlist last — and within
each band, `Song::$position` ascending.

This is worth stating loudly because it is the one place where the API's canonical ordering and the
playlist's ordering are deliberately opposite, and a reasonable implementer would get it backwards.
The API preserves billing order because that is how a lineup is *described*; the playlist preserves
stage order because that is how the night was *experienced*.

### The caps, and how they cut

A festival bill is arithmetically dangerous: 8 bands × 20 songs = 160 searches, which on YouTube is
16,000 units — **more than the application's entire daily quota** (spec 12 §7, `docs/external-apis.md`
§YouTube). So:

| Constant | Value | Effect |
|---|---|---|
| `GENERATION_MAX_BANDS` | **4** | Keep the four highest-billed (lowest `billingOrder`) bands |
| `GENERATION_MAX_SONGS` | **60** | After the band cap, drop whole bands from the lowest-billed end until the total fits |
| *(last resort)* | — | If the headliner alone exceeds 60 songs, truncate its setlist at 60 and report it |

Cutting from the **lowest-billed end** is the right direction: the user came for the headliner, and
the support act they may not have arrived in time to see is the cheapest thing to lose. Every
omission is a report code — `BANDS_OMITTED_FOR_LENGTH` with the names, `SETLIST_TRUNCATED` with the
count — so nothing disappears silently.

### Per-band degradation

Each band's setlist is selected **independently** by D-132's rule, and a band with no usable setlist
contributes a report line (`NO_SETLIST_FOR_BAND` with the band name) and **zero tracks** — it does not
fail the job and does not stop the other bands. A concert where *no* band has a usable setlist is
T-10: `completed`, `no_source_material`, no provider playlist created.

### Concurrency: one job, one message, sequential over bands — D-144

A multi-band generation is **one** message processed sequentially, not one message per band. Fanning
out would need a coordinator to decide when all bands are done, to order the insertion across them,
and to own a single report — which is a workflow engine, which prompt 13's own risk note forbids.
Sequential over four bands costs wall-clock time the user is already watching a progress bar for.

**The rest of the concurrency answer, deliberately small:**

- One Messenger transport, `async_playlist`, over the Redis DSN already in `.env.example`.
  **`PLAYLIST_WORKER_COUNT = 2`** to start — enough that one slow job does not stall the queue,
  small enough that two users cannot jointly exhaust a provider's per-second rate limit.
- **Fairness is structural, not scheduled.** D-129's partial unique index means one live job per
  (concert, provider), and a second constraint — **one live job per user across all concerts** —
  means a single user cannot enqueue twenty jobs and starve everyone else. No priority queue, no
  weighted fair queueing, no per-tenant scheduler. Prompt 22 (entitlement and quota) is where
  per-user limits become a product feature; this is only the anti-starvation floor.
- **`symfony/lock` per job id** (the same pattern `StreamingTokenManager` uses for refresh, D-79) so
  a redelivered message cannot execute concurrently with the run that is already going. T-20 depends
  on this.
- Messenger retry: **3 attempts, 5 s / 30 s / 180 s, 20 % jitter**, `failure_transport: failed`
  (a Doctrine queue, so a poisoned message is inspectable rather than lost).

---

## 11. Ordering, and naming

### Order is preserved end to end — D-140

Setlist order **is** the show, so it survives every stage:

1. `PlaylistTrack` rows are created **up front**, one per source song, in `sourcePosition` order,
   before any matching happens.
2. Matching writes into those rows; it never appends, never reorders, and never deletes.
3. Insertion walks them in ordinal order and sends only the matched ones — so a missing song at
   position 7 leaves positions 6 and 8 adjacent in the playlist while `sourcePosition` still records
   the gap. **That gap is the report.**
4. Batching preserves order because batches are contiguous slices of the same ordered list, and the
   watermark advances monotonically.
5. A medley contributes one row per segment, in segment order, sharing a `sourcePosition` and
   differing by `segmentIndex` (prompt 12, D-114).
6. Multi-band concatenation happens at row-creation time in stage order (D-133), so ordering across
   bands is a property of the data, not of the insertion loop.

An integration test asserts the property directly: a fixture setlist with a forced miss in the middle
produces provider insert calls whose id sequence equals the matched subsequence of the source order.

### What the playlist is called — D-140

`App\Service\Playlist\Naming\PlaylistNamer`, from the `Concert` and its lineup:

| | Pattern | Example |
|---|---|---|
| **Name** | `<Headliner> — <Venue city>, <D Mon YYYY>` | `Metallica — Amsterdam, 22 Aug 2023` |
| **Name**, no venue | `<Headliner> — <D Mon YYYY>` | `Metallica — 22 Aug 2023` |
| **Description** | `The setlist from <lineup>, <venue>, <date>. Built by Setlistify.` | plus `(17 of 22 songs matched)` when partial, plus `Setlistify job <shortId>` |

The name is **deterministic from the concert**, which is what makes F-14's "find the possible orphan
in your account" instruction actionable. Truncation to the provider's own length limit happens inside
the adapter — a provider-shaped concern, per D-73's spirit. Playlists are **private** (D-87:
`PlaylistDraft` carries no visibility flag, and the adapter never exposes one).

**Can the user change it? Not in the MVP.** Not because it is hard, but because renaming provider-side
would need a tenth port method that D-71 freezes, and the user can rename it in Spotify or YouTube in
two taps — where the change actually persists and where they already are. Our stored
`Playlist.name` is what our own screens render, and a future rename feature is additive, touching one
column and one endpoint.

---

## Out of Scope

| Not here | Where it belongs |
|---|---|
| The matching algorithm, normalization, confidence, thresholds | Prompt 12 (approved). This document consumes its five outcome values and three confidence bands as given |
| **Any implementation** — entities, migrations, handler, endpoints | Prompt 14 (fast mode) and prompt 17 (normal mode). This is a spike: it produces this file and nothing else |
| The setlist-selection, version-selection, progress and report **screens** | Prompt 15 (design), 16 (fast mode UI), 17 (normal mode UI). This document specifies the codes and the data; not a sentence of user-facing English is final here |
| Playback of the generated playlist | Prompt 19. `playlistEmbedUrl()`/`playlistDeepLink()` already exist on the port; `Playlist.providerPlaylistId` is what prompt 19 reads |
| YouTube's unit arithmetic, quota counter and calibration | Prompt 18. F-04 is provider-agnostic **by construction** — the pipeline never learns what a unit is |
| Multi-provider generation (one concert, two providers at once) | Prompt 18 makes a second provider possible; D-129's index already permits one job per (concert, provider), so two providers is two jobs and needs no new design |
| Per-user generation limits and entitlements | Prompt 22. §10's "one live job per user" is an anti-starvation floor, not a product limit |
| Push notification on completion | Noted in prompt 16's risks. A feature with its own permission flow, not a progress mechanism (D-128) |
| Editing a playlist after creation — reorder, add, remove, rename | Explicitly out of prompt 17's scope too. Would need port methods D-71 freezes |
| A backoffice write path for jobs (force-retry, force-unblock) | D-142 ships read-only. If it is ever needed it goes through `AuditLogger` like every other admin write |
| Sharing the playlist | Prompt 21 |
| A general workflow/step engine | Prompt 13's own risk note. Eleven states, one handler, two `if`s. Anything more is speculative |

---

## Dependencies

**Must be true before prompt 14 implements this**

| Dependency | Provides | Status |
|---|---|---|
| **Prompt 09 merged — setlist.fm integration** | `Setlist`/`Song` rows, `SetlistGateway`, `CachedFetch` with `budgetResetAt`, and `hydrateSetlistsPage()` hydrating full songs — which is what makes D-131's zero-cost selection true | **Met** |
| **Prompt 10 merged — streaming port** | The nine frozen methods, `PlaylistDraft`/`ProviderPlaylist`/`ProviderTokens`, `StreamingProviderLocator`, `StreamingTokenManager::usableTokens()`, and the six typed exceptions §4 is built on | **Met** |
| **Prompt 11 merged — provider configuration** | `ProviderRegistry::isAvailable()` for the per-stage-boundary re-check, and `ProviderDisabledException` for F-07 | **Met** |
| **Prompt 12 approved — song matching** | `TrackMatcher`, the five outcome values, the three confidence bands, `TrackResolution` + `algorithmVersion`, and the resolved Fast-mode CHOICE behaviour that `ReviewStage` depends on | **Met — approved 2026-08-23** |
| **A Messenger transport** | Nothing async exists today: `src/Message/` and `src/MessageHandler/` contain only READMEs saying so, and `config/packages/messenger.yaml` still routes everything to `sync://`. `MESSENGER_TRANSPORT_DSN` exists in `.env.example` and is unused | **To build — prompt 14, D-125** |
| **A long-running worker process** | `messenger:consume async_playlist`, supervised, in the backend container and in the deployment target. Without it every job sits in `queued` forever | **To build — prompt 14**, and it must reach `compose.yaml`, the root `README.md` and the deployment docs |
| **Two cron entries** | `app:playlist:resume-blocked` (every 5 min) and `app:playlist:expire-jobs` (nightly) — the same deployment-cron pattern `app:setlist:refresh` already uses (D-65), since there is no in-app scheduler | **To build — prompt 14** |
| `Playlist`, `PlaylistTrack`, `PlaylistGenerationJob` entities and their migration | §2 | **To build — prompt 14** |
| A linked, `connected` `StreamingAccount` for the generating user | Every provider call | **Met** (prompt 10) |
| Redis and PostgreSQL from `compose.yaml` | The transport, the locks, the durable job state | **Met** |
| The architecture tests from AC-9.4 / D-82 | Keeping `App\Service\Playlist\` provider-free | **Met** — they already scan `backend/src/` |

**Depended on by**

- **Prompt 14 (fast mode backend)** — implements §§1–5 and §§8–11 in full, and is required to record
  any divergence back into this document in the same branch.
- **Prompt 16 (fast mode UI)** — consumes §7's polling contract, §4's `blockedReason` vocabulary
  (each of which is one of prompt 16's designed degraded states) and §8's report codes.
- **Prompt 17 (normal mode)** — implements §6, and inherits the two suspension states, the two TTLs
  and the staleness table rather than designing them.
- **Prompt 18 (YouTube adapter)** — inherits F-04's contract: raise `QuotaExhaustedException` from
  inside the adapter when a call would overrun, and the pipeline handles it with no YouTube-specific
  code anywhere upstream.
- **Prompt 19 (playback)** — reads `Playlist.providerPlaylistId` and the port's two URL methods.
- **Prompt 22 (entitlement and quota)** — replaces §10's anti-starvation floor with real per-user
  limits, at the pre-flight stage, which is the seam it needs.

**Assumptions** *(labelled as assumptions, not verified facts)*

- `SetlistNormalizer::hydrateSetlistsPage()` hydrates full `Song` rows from the index payload — read
  from the shared `hydrateOne()` call path, but stated as an assumption about **setlist.fm's index
  response** rather than about our code. If the index ever returns song-less summaries, D-131's
  "zero extra calls" becomes "one detail fetch for the chosen setlist per band", and only that
  sentence changes.
- A provider's playlist-create call is not itself idempotent and offers no client-supplied request
  token in the general case. If a specific provider does (prompt 18 should check YouTube's), that
  adapter can close D-136's window locally without changing this design.
- Spotify accepts up to 100 track ids per add request; YouTube inserts one item per call. Stated from
  current documentation; `INSERT_BATCH_SIZE`'s `min(50, provider maximum)` form survives either being
  wrong.
- A festival slot is 25–40 % of a headline set and a support slot 40–55 %, which is what places
  D-132's ratio at 0.60. An informed estimate, and the first constant this document expects to
  revise against real data.
- ~30 seconds for a typical warm-cache 25-song generation, from spec 12 §2's network arithmetic
  (~4–6 s of search plus insertion and overhead). §7's poll-count estimate scales linearly and its
  conclusion survives being wrong by 3×.
- The Redis Messenger transport is sufficient for this workload. If message durability across a Redis
  restart ever matters more than throughput, the Doctrine transport is a one-line DSN change and no
  other part of this design moves.

---

## Risks and Resolved Questions

| # | Risk | Impact | Mitigation / decision |
|---|---|---|---|
| R-1 | **Building a workflow engine.** Eleven states, two suspension points and a resume sweeper is exactly the shape that invites a generic step registry | High, and enjoyable to do — which is what makes it dangerous | Prompt 13's own risk note is the acceptance criterion. One entity, one handler, two mode `if`s, no DSL. `JobStateMachine` is deliberately a table of legal edges and nothing more |
| R-2 | **`blocked` is rendered as an error by the UI**, undoing the whole design | **High** — it converts every "we'll finish this at midnight" into "something went wrong" | Named as a first-class state here, given its own reason enum, and carried into prompt 16's brief, which already forbids a red error colour on partial results. The API never sends an HTTP error status for a `blocked` job |
| R-3 | **The creation-indeterminate window (F-14)** produces a confusing user experience the first time it fires | Low frequency, high confusion | Deliberately chosen over a silent duplicate, argued in D-136. The deterministic playlist name and the `Setlistify job <shortId>` description marker are what make the user's side of it actionable |
| R-4 | **`SUBSTANTIAL_RATIO = 0.60` is a guess** | Medium — a wrong value silently builds playlists from the wrong night | Stated as a guess wherever it appears; `selectionReason` is persisted and rendered on **every** playlist, so a wrong default is visible to users and to the backoffice rather than invisible. Normal mode is the override |
| R-5 | **Suspended jobs accumulate**, which prompt 13 flags explicitly | Medium, and quiet | Hard TTLs (7 days / 72 hours), a nightly sweeper, and the large JSONB payloads dropped on expiry while the small row is kept. An indexed `(state, expires_at)` makes the sweep a bounded query |
| R-6 | **Polling feels dated and somebody replaces it** with SSE mid-project | Medium — it would fork the client across web and native | D-128 writes the platform reasoning down so the argument does not have to be re-had. Revisit only when a real measurement, not an aesthetic, motivates it |
| R-7 | **The multi-band caps are wrong for real festivals** — 4 bands and 60 songs may be too few for the concerts people actually track | Medium | Both are named constants with report codes on every cut, so the cases where they bite are *counted* in the backoffice rather than guessed at. The quota arithmetic (spec 12 §7) is the reason they exist at all, and prompt 18's quota increase is what would relax them |
| R-8 | **The worker is not running** in some environment and every job sits in `queued` | High and embarrassing | Listed as a blocking dependency with three deliverables (compose service, README operations entry, deployment doc). The backoffice dashboard's "jobs started vs completed" is the number that makes it obvious |
| R-9 | **Per-song progress writes contend** with the main run's transaction | Low | Progress is its own short transaction on its own row, taken between provider calls, never inside one. Twenty-five short `UPDATE`s over 30 seconds |
| R-10 | **Prompt 14 diverges from this spec silently** because reality disagrees | Medium | Prompt 14's brief already requires updating these specs in the same branch. The concrete instances to expect: `SUBSTANTIAL_RATIO`, `INSERT_BATCH_SIZE`, the retry backoff, and §8's investigate-thresholds |
| R-11 | **`TrackResolution` makes a resumed run cheap — until `algorithmVersion` moves**, at which point a resumed job re-spends its entire provider budget | Low-medium | §6's staleness rule keeps user choices and re-scores only unanswered songs; and D-121 keeps old-version rows, so a resumed job under a bumped version misses the cache by design rather than by accident. Worth measuring once there is traffic |

**Resolved on approval — 2026-08-23**

The four questions this spike left open were put to the product owner with the recommendations below,
and **every recommendation was accepted**. They are decisions now, not questions.

1. **`GENERATION_MAX_BANDS = 4` and `GENERATION_MAX_SONGS = 60` — RESOLVED: 4 and 60.** Quota-driven,
   not product-driven; a genuine festival is the case they cut, and each cut is a counted report line
   rather than a silent truncation. Revisit when prompt 18's YouTube quota increase changes the
   arithmetic.
2. **Stage order rather than billing order** for multi-band playlists (D-133) — **RESOLVED: stage
   order.** `ORDER BY billingOrder DESC` — support acts first, headliner last, the order the night
   actually happened in, even though it is the opposite of how the API lists a lineup.
3. **F-14's honest gap — RESOLVED: surface it; keep D-71 frozen.** A possible orphan playlist is
   surfaced to the user with a `create-anyway` action rather than silently duplicated or closed by a
   tenth port method.
4. **`SUSPENDED_JOB_TTL` of 7 days / 72 hours (D-138) — RESOLVED: keep both figures.** The candidate
   lists are genuinely stale by 72 hours, and expiry pre-fills a new job from the kept `userChoices`
   rather than losing the user's work. Fast mode never suspends, so this ships as columns and a
   sweeper with no runtime effect until prompt 17.

---

## Recommendation Summary

**Model waiting, and everything else becomes simple.**

The instinct when specifying a pipeline with a dozen failure modes is to build machinery
proportionate to the number of failures — a retry framework, a step registry, a compensation model.
This design does the opposite, and the reason is that eight of the twelve failure modes prompt 13
lists are not failures at all. They are the world being temporarily unready: a budget that resets at
midnight, a quota that resets tomorrow, a token the user can re-link in ten seconds, a provider an
operator will switch back on. Giving those one shared state — **`blocked`**, with a reason and a
resume instant — collapses eight branches into one, and turns "recoverable state" from a phrase in
an acceptance criterion into a column.

What remains after that collapse is small enough to hold in your head:

- **Three routes to `failed`**, all named, none of them "some songs were missing".
- **Two idempotency mechanisms** — a creation marker and an insertion watermark — leaving a residual
  window exactly one provider call wide, and one indeterminate case that is **shown to the user**
  rather than silently gambled on, because D-71's frozen port is worth more than closing a
  sub-second window by guessing.
- **Two `if` statements** separating Fast from Normal, so prompt 17 adds suspension to a pipeline
  instead of writing a second one — and a single test that fails the moment somebody forks it.
- **One ordering decision worth arguing about**: match everything first, create the provider playlist
  last. It makes upfront quota refusal free, keeps empty playlists out of people's accounts, and
  shrinks the one gap idempotency cannot close.
- **Two numbers in two columns**, on a backoffice panel that ships with fast mode rather than after
  it, because prompt 14's brief is right that this is where the product succeeds or disappoints, and
  a number nobody stored is a number nobody will have.

The one place this document spends deliberately is **`blocked`** and its two sweepers. That machinery
exists so that the most common bad day — YouTube's quota gone by three in the afternoon — is a
sentence in the UI that tells the truth and a job that finishes itself overnight, rather than an
error the user has to understand and act on. That is the same principle spec 12 organized itself
around, applied one layer up: **the product's honesty is the feature, and the state machine is where
it is enforced.**

---

## Documentation to update *(when prompt 14 implements this, not now)*

This is a spike; it produces this file and nothing else. The list below belongs to the implementing
branch, per `CLAUDE.md`'s mandatory check (`/doc-check`):

- **`docs/architecture.md`** — record **D-125**–**D-144**; rewrite §8 with the real state machine and
  the failure taxonomy; fill in §10's `Playlist`/`PlaylistTrack` sketch and add
  `PlaylistGenerationJob`; extend §9 with the generation views and the dashboard panel.
- **`docs/env-vars.md`** *and* **`backend/.env.example`** — `MESSENGER_TRANSPORT_DSN` promoted from
  unused to used, plus `PLAYLIST_WORKER_COUNT`, `GENERATION_MAX_BANDS`, `GENERATION_MAX_SONGS`,
  `SUSPENDED_JOB_TTL_*` and `SUBSTANTIAL_RATIO`. Both files or neither.
- **Root `README.md`** — the worker service and the two new cron entries, alongside
  `app:setlist:refresh`'s existing operations entry.
- **`compose.yaml`** — the `messenger:consume` worker service.
- **`docs/external-apis.md`** — the generation-shaped consumption pattern (searches plus inserts per
  generation) under §YouTube, and the change-log entry.
- **A new `backend/src/Service/Playlist/README.md`** — restating the provider-free rule and the
  `JobStateMachine`-is-the-only-writer rule, in the same spirit as the existing service READMEs.
- **`backend/src/Message/README.md`** and **`backend/src/MessageHandler/README.md`** — currently both
  say "out of scope, nothing is wired". Prompt 14 is the branch that makes them untrue.
- **The OpenAPI spec** — regenerated from prompt 14's API Platform resources. No endpoint is listed in
  any README or in this spec.

---

**Approved 2026-08-23.** This spike carries decisions **D-125**–**D-144**, all now settled; the four
questions it originally left open were resolved in the affirmative on the same date and are recorded
above. The five most consequential decisions — and the five most worth disagreeing with — are
**D-126** (`blocked` as a first-class state, which is what makes eight failure modes non-failures),
**D-135** (match everything before creating the provider playlist, which makes upfront quota refusal
free), **D-136** (the creation marker, and surfacing the indeterminate case to the user rather than
adding a tenth port method), **D-132** (*most recent substantial*, at 0.60 of the median with a floor
of 8) and **D-133** (one playlist per concert, in stage order, capped at 4 bands and 60 songs). The
four open questions above are the only things deliberately left undecided.
