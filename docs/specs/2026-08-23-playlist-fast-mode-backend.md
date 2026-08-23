# FEATURE — Playlist generation: Fast mode (backend)

| | |
|---|---|
| **Spec ID** | `2026-08-23-playlist-fast-mode-backend` |
| **Backlog prompt** | `docs/prompts/14-playlist-fast-mode-backend.md` |
| **Command** | `/feature playlist-fast-mode-backend` |
| **Primary agent** | `backend-engineer` |
| **Type** | **FEATURE — implementation follows this document directly.** One branch `feature/playlist-fast-mode-backend`, one migration, one PR |
| **Depends on** | `09` setlist.fm (merged) · `10` streaming port (merged) · `11` provider configuration (merged) · `12` song matching (**approved 2026-08-23**) · `13` playlist pipeline (**review requested**) |
| **Implemented by** | *(this is the implementation)* — consumed by `16` fast-mode UI, `17` normal mode, `18` YouTube adapter |
| **Decisions** | **D-145** – **D-160** |
| **Status** | **Approved — 2026-08-23.** P-1…P-4 accepted exactly as recommended; nothing deferred |

---

## Overview

### What this feature is

The first implementation of the two spikes. Prompt 12 decided *"which track is this song?"*; prompt
13 decided *"what happens around it"*. This document decides **nothing about either** — it turns both
into entities, migrations, services, a Messenger handler, four API operations and a backoffice screen,
and it stops where they stop.

The user-visible outcome is one sentence: **a user points at a tracked concert, asks for a playlist,
and gets one — no further input — built from the band's most recent substantial setlist, with songs
matched automatically, plus an honest report of anything that could not be matched.**

The quality bar, restated from the prompt because it is the thing most easily lost in implementation:
**it degrades, it does not fail.** A playlist with 14 of 19 songs and a clear explanation is a
success and reaches `completed`. An error page because three songs were missing is a bug.

### This spec follows the spikes; it does not re-decide them

Prompt 14's brief is explicit: *"Follow those specs; if implementation reveals they were wrong,
update them in the same branch rather than diverging silently."* Accordingly:

- Every algorithm, weight, threshold, transform, lexicon rule, cache key and TTL comes from
  **spec 12** (`docs/specs/2026-08-22-spike-song-matching.md`, D-106–D-124) verbatim.
- Every state, transition, failure-mode landing, idempotency mechanism, selection rule, ordering
  rule, TTL, batch size, poll cadence and metric column comes from **spec 13**
  (`docs/specs/2026-08-23-spike-playlist-pipeline.md`, D-125–D-144) verbatim.
- This document's own decisions, **D-145–D-160**, are *only* about things neither spike decided:
  identity types, migration shape, Messenger wiring, the API resource surface, the delete semantics,
  the test doubles, and the CI gate.

Where implementation forces a divergence from a spike, it is recorded here **and written back into
the spike in the same branch** — the two known instances are flagged inline (D-146 on identity types,
D-150 on the `updatedAt` column) and are additive, not reversals.

### Four items, accepted exactly as recommended on approval (2026-08-23)

Spec 13 left exactly four questions open, each with a stated recommendation. **Every recommendation
was accepted, unchanged, on approval.** They are decisions now, not questions.

| # | Question (spec 13) | Decided as | Blast radius if ever revisited |
|---|---|---|---|
| **P-1** | `GENERATION_MAX_BANDS` / `GENERATION_MAX_SONGS` | **4 bands / 60 songs** — accepted | Two container parameters + two `.env.example` lines. No schema, no code |
| **P-2** | Multi-band playlist ordering | **Stage order** — `ORDER BY billingOrder DESC`, support acts first, headliner last — accepted | One `ORDER BY` in `SetlistSelectionStage` + one integration test |
| **P-3** | F-14's honest gap (indeterminate playlist creation) | **Surface it to the user; keep the port frozen at nine methods** — accepted | The `create-anyway` operation and the `creation_indeterminate` failure reason. Reversing it would mean a tenth port method (D-71) |
| **P-4** | `SUSPENDED_JOB_TTL` — 7 days / 72 hours | **7 days (setlist choice) / 72 hours (version choice)** — accepted | Two container parameters. Fast mode never suspends, so this ships as columns + a sweeper only |

P-4 is worth a word: Fast mode has no suspension point, so the 72-hour figure has **no runtime
effect in this feature**. It ships because the columns, the `(state, expires_at)` index and
`app:playlist:expire-jobs` are cheaper to build once than to retrofit, and because prompt 17's brief
forbids designing them itself.

### The prompt's acceptance criteria, mapped

Every checkbox in `docs/prompts/14-playlist-fast-mode-backend.md`, and where this document satisfies
it. Nothing is left as TBD.

| # | Prompt-14 acceptance criterion | Satisfied by |
|---|---|---|
| AC-1 | A well-covered band produces a **complete playlist in setlist order** | §3 (`MatchingStage` → `CreationStage` → `InsertionStage`), §2's `PlaylistTrack.ordinal`/`sourcePosition`, §7 US-1, test T-INT-01 |
| AC-2 | A band with **no setlist.fm data** returns a clear, **non-error** "no setlists available" outcome | §3 `SetlistSelectionStage` → T-10 → `completed` / `resultKind = no_source_material`; §5's F-02/F-03 rows; §6's `GET` job payload carries no error status; tests T-INT-04, T-INT-05 |
| AC-3 | Unmatched songs still produce a playlist **plus a report naming exactly what was missed and why** | §2 (`PlaylistTrack.outcome` + `reasonCode` on **every** source song), §4's outcome vocabulary, §6's `GET /api/playlists/{id}` report array; test T-INT-06 |
| AC-4 | **Setlist order preserved, including gaps in the middle** | §3's up-front skeleton rows (D-140), `InsertionStage`'s contiguous batches; test T-INT-07 asserts the provider insert sequence equals the matched subsequence |
| AC-5 | **Covers, medleys and non-song entries** behave as prompt 12 specified | §4's special-case table (D-113/D-114/D-115/D-116), `PlaylistTrack.segmentIndex`; tests T-UNIT-06…T-UNIT-09 |
| AC-6 | **Retrying a failed job creates no duplicate playlist or tracks** | §5's three-level idempotency (partial unique index, creation marker, insertion watermark); tests T-INT-08, T-INT-09, T-INT-10 |
| AC-7 | **Quota exhaustion mid-run leaves a defined, recoverable state** | §5's F-01 and F-04 rows → `blocked` + `resumableAfter`; `app:playlist:resume-blocked`; tests T-INT-11, T-INT-12 |
| AC-8 | **A provider disabled mid-run fails cleanly with a typed error**, per prompt 11 | §3's stage-boundary `ProviderRegistry::isAvailable()` re-check, F-07 → `blocked`/`provider_disabled`; test T-INT-13 |
| AC-9 | **Match quality meets the fixture threshold, and the test fails if it regresses** | §8's `@group matching-quality` harness, auto-accept precision ≥ 0.95 on Spotify, wired into `composer test`; test T-QUAL-01 |
| AC-10 | **Generation never blocks an HTTP request; progress is observable throughout** | §9's Messenger wiring (the `POST` returns before any provider call), §6's polling contract with `songsProcessed`/`songsTotal`; tests T-INT-14, T-FUNC-03 |

### Load-bearing rules this feature does not reverse

| Rule | Source | How this implementation honours it |
|---|---|---|
| The streaming port is the only way to reach a provider | `CLAUDE.md`, `docs/architecture.md` §4 | `App\Service\Playlist\` type-hints `App\Service\Streaming\StreamingProviderInterface` and resolves adapters through `App\Service\Streaming\StreamingProviderLocator`. A new static test (`PlaylistServiceIsProviderFreeTest`, D-159) scans the directory for provider symbols and provider key literals |
| No `Spotify`/`YouTube` symbol outside its adapter directory | `CLAUDE.md`, D-82 | Neither `App\Service\Playlist\` nor `App\Service\Matching\` names a provider. The existing `App\Tests\Unit\Service\Streaming\SpotifySymbolIsolationTest` already scans `backend/src/` and stays green; per-provider matching profiles are keyed by `StreamingProviderInterface::key()`, a runtime string (D-110/D-118) |
| `SetlistGateway` is the only door to setlist.fm | `CLAUDE.md`, D-58 | `SetlistSelectionStage` depends on `App\Service\Setlist\SetlistGateway` and `App\Repository\SetlistRepository` only. `App\Service\Matching\` holds no setlist.fm reference at all (spec 12 §7). `App\Tests\Unit\Service\Setlist\SetlistGatewayIsOnlyDoorTest` stays green untouched |
| setlist.fm responses are always cached; budget is a decision | `CLAUDE.md`, D-63/D-65 | Cached `Setlist`/`Song` rows first; at most `GENERATION_SETLIST_PAGES = 1` index page per band per generation; never a per-setlist detail fetch; never a speculative freshness check (D-131) |
| Provider state is read at runtime via `ProviderRegistry` | `CLAUDE.md`, D-89 | `App\Service\Provider\ProviderRegistry::isAvailable()` is re-checked at **every stage boundary**, not once at dispatch (D-134/F-07) |
| Playlist generation degrades, it does not fail | `CLAUDE.md` | Three routes to `failed` and no others (§5). Partial success is `completed`. Every unmatched song is a row with an outcome, never an exception |
| A user-scoped resource returns 404, never 403 | `CLAUDE.md`, D-27, architecture §11 | `PlaylistOwnerExtension` and `PlaylistGenerationJobOwnerExtension` copy `App\Security\ConcertOwnerExtension`'s shape exactly (D-143/D-157). **`ConcertOwnerExtension` is not modified, not made role-aware, and gains no `ROLE_ADMIN` branch** |
| The backoffice is a separate channel and never weakens that gate | `CLAUDE.md`, D-47 | `PlaylistGenerationJobCrudController` and `PlaylistCrudController` read across owners through Doctrine directly, inside the audited, 2FA-gated admin firewall, and touch no query extension (D-158) |
| The backoffice edits behaviour, never credentials | `CLAUDE.md`, D-46 | Both new admin controllers are **read-only** — not even a retry button (D-142/D-158) |
| Provider credentials never leave the secrets layer | `CLAUDE.md` | The job row stores a provider **key** and a `StreamingAccount` id. It never stores a token; `App\Service\Streaming\Link\StreamingTokenManager::usableTokens()` supplies one per call and nothing persists it (D-135) |
| The port is frozen at nine methods | D-71 | Nothing here adds one. F-14's indeterminate window is surfaced to the user precisely *because* closing it would need a tenth method (P-3) |
| The OpenAPI spec is the single source of truth for endpoints | `CLAUDE.md`, API Contract | §6's operations are declared on API Platform resource classes in the same change. **No endpoint is listed in any README, and none in this document is a substitute for the generated spec** |
| CI runs no integration tests against real external APIs | D-2, D-70, D-85 | §8's whole plan runs against a test-double adapter and committed fixtures; the fixture harness makes zero outbound calls |

### Existing groundwork this builds on, not around

| Already in place | Where | Used for |
|---|---|---|
| `StreamingProviderInterface` — nine methods, `searchTrack()` / `createPlaylist()` / `addTracks()` / `playlistDeepLink()` / `playlistEmbedUrl()` | `backend/src/Service/Streaming/StreamingProviderInterface.php` | Every provider interaction |
| `StreamingProviderLocator`, `UnknownProviderException` | `backend/src/Service/Streaming/` | Adapter resolution by `key()`; F-15 |
| Six typed exceptions — `TokenExpiredException`, `RateLimitedException`, `QuotaExhaustedException`, `NotFoundException`, `RegionRestrictedException`, `ProviderUnavailableException` | `backend/src/Service/Streaming/Exception/` | The spine of §5's taxonomy |
| `StreamingTokenManager::usableTokens(StreamingAccount): ProviderTokens` — proactive, single-flight per account, flips `needs_reauth` (D-79/D-80) | `backend/src/Service/Streaming/Link/StreamingTokenManager.php` | Every token acquisition. The pipeline never calls `refreshToken()` |
| `ProviderRegistry::isAvailable()` / `configFor()` / `all()`, Redis-cached, fails open (D-105) | `backend/src/Service/Provider/ProviderRegistry.php` | Provider selection and every stage-boundary re-check |
| `ProviderDisabledException` | `backend/src/Service/Provider/ProviderDisabledException.php` | F-07 |
| `SetlistGateway::fetchArtistSetlistsPage(mbid, page)` returning `CachedFetch` with `->reason` and `->budgetResetAt` | `backend/src/Service/Setlist/` | F-01, and `budgetResetAt` **is** the `resumableAfter` value |
| `SetlistNormalizer::hydrateSetlistsPage()` — hydrates full `Song` rows from the index payload | `backend/src/Service/Setlist/SetlistNormalizer.php` | D-131's zero-extra-call selection |
| `BandIdentityResolver::ensureResolved(Band): BandResolutionOutcome` with states `resolved`/`ambiguous`/`no_presence`/`unresolved` | `backend/src/Service/Setlist/BandIdentityResolver.php` | F-02 |
| `Setlist` (`setlistfmId`, `eventDate`, `songCount`, `isEmpty`, venue fields, `tourName`) and `Song` (`position`, `setLabel`, `title`, `coverOfName`, `coverOfMbid`, `withName`, `info`, `isTape`) | `backend/src/Entity/` | Selection, ordering, and every matcher input signal |
| `ConcertBand::getBillingOrder()` — 0 = headliner (D-25), `Concert::$concertBands` ordered by it | `backend/src/Entity/ConcertBand.php`, `Concert.php` | Multi-band ordering (D-133, P-2) |
| `BandResolver::normalize()` — pure static, NFKD, article-stripping | `backend/src/Service/Concert/BandResolver.php` | The **artist** side of every comparison, verbatim (D-106) |
| `SpotifyTrackMapper` + `naiveConfidence()` (D-83, "deliberately provisional") | `backend/src/Service/Streaming/Spotify/` | The method this feature **deletes** (D-147) |
| `TrackCandidate`, `SongQuery`, `PlaylistDraft`, `ProviderPlaylist`, `ProviderTokens` | `backend/src/Service/Streaming/Model/` | Unchanged in shape except `TrackCandidate`'s new signal fields (D-119/D-147) |
| `ConcertOwnerExtension` + `ConcertVoter` + `ConcertLocator` | `backend/src/Security/`, `backend/src/State/` | The exact pattern the two new owner extensions copy |
| `AbstractAdminCrudController`, `DashboardController`, `AuditLogger`, `MaskedEmailField` | `backend/src/Controller/Admin/`, `Service/Admin/`, `Field/` | The shape §7's backoffice additions copy rather than re-invent |
| `symfony/messenger`, `symfony/lock`, `MESSENGER_TRANSPORT_DSN` in `.env.example` | `backend/composer.json`, `backend/.env.example` | **Present but entirely unwired** — `config/packages/messenger.yaml` routes everything to `sync://`, and `src/Message/` and `src/MessageHandler/` hold a README apiece saying so. §9 is where that changes |
| Redis + PostgreSQL from `compose.yaml` | root `compose.yaml` | Transport, locks, resolution cache, durable job state |

---

## Goals

| Goal | Success looks like |
|---|---|
| One tap, one playlist | A `POST` returns in well under a second with a `queued` job; ~30 s later the user's provider account holds a playlist in setlist order |
| A partial result is a success | 14 of 19 matched reaches `completed` with `resultKind = partial`, an HTTP 200 on every read, and a per-song report. No error status is ever emitted for a degraded outcome |
| Waiting is not failing | Budget exhaustion, quota exhaustion, an expired token and a disabled provider all reach **`blocked`**, keep every byte already computed, and resume on their own or on a user action |
| A retry cannot duplicate | Two mechanisms, both persisted; the one residual window is one provider call wide and is *reported*, not hidden |
| The matcher is honest | Auto-accept precision ≥ **0.95** on the Spotify fixture set, enforced by a build-failing test, with the tuned thresholds written back into spec 12 |
| The two numbers exist from day one | `durationMs`, `stageTimings` and six outcome counters are columns, aggregated by a backoffice panel that ships **in this branch** |
| The provider seam holds | `App\Service\Playlist\` and `App\Service\Matching\` contain no provider symbol and no provider key literal, enforced by static tests. Prompt 18 adds a directory and a profile, nothing else |
| Prompt 17 adds two `if`s | The state machine already contains `awaiting_setlist_choice` and `awaiting_version_choice`; `ReviewStage` and `SetlistSelectionStage` already have the guards, wired to `mode = fast` |

---

## User Stories

Feature stories, about the product's users — the Setlistify listener, the operator, and the two
engineers who inherit this code.

### US-1 — Generate a playlist without being asked anything

> As a **user with a tracked concert and a linked streaming account**, I want to ask for a playlist
> and get one, so that I can replay the show I went to without doing any work.

**Acceptance criteria**

- **AC-1.1** `POST /api/playlist-generation-jobs` with a concert id returns **201** and a job in
  `queued` within one request cycle, having made **zero provider calls** and **zero setlist.fm calls**
  on the request thread.
- **AC-1.2** When the user has not chosen a provider, the job uses the default from
  `ProviderRegistry::all()` (`isDefault`), restricted to providers the user has a `connected`
  `StreamingAccount` for. A hardcoded provider key appears nowhere.
- **AC-1.3** For a concert whose headliner has a dense setlist.fm presence, the finished playlist
  exists in the user's provider account, is **private**, is named per `PlaylistNamer` (D-140), and
  contains the matched tracks **in setlist order**.
- **AC-1.4** `GET /api/playlists/{id}` returns the playlist, its `externalUrl`, and one report entry
  per source song — including the songs that produced no track.
- **AC-1.5** A second `POST` for the same `(concert, provider)` while a job is live returns **200**
  with the **existing** job, never a second job and never a 409.

### US-2 — Be told the truth when it does not fully work

> As a **user whose band's setlist is thin, obscure or missing**, I want a plain, non-alarming account
> of what happened, so that I trust the tracks that *did* make it.

**Acceptance criteria**

- **AC-2.1** A band with no setlist.fm presence, or one whose only setlists are empty, produces
  `state = completed`, `resultKind = no_source_material`, **no provider playlist**, and a job-level
  report code (`NO_SETLIST_FOR_BAND`). Every read is HTTP 200.
- **AC-2.2** Songs the matcher rejects produce `PlaylistTrack.outcome = not_found` with
  `reasonCode = TRACK_NOT_IN_CATALOG`; the playlist is still created and still complete in the sense
  of "everything we could find".
- **AC-2.3** A `CHOICE`-band match (`0.55 ≤ conf < 0.80`) is **included and flagged**
  (`matched_low_confidence`), per spec 12's resolved question 1 — never dropped.
- **AC-2.4** A cover reports `COVER_OF` with the original artist's name; a live-only match reports
  `LIVE_VERSION_ONLY`; a tape or performance artifact reports `skipped` and is **excluded from the
  match-rate denominator**.
- **AC-2.5** No degraded outcome is ever expressed as an HTTP error status, an exception page, or a
  `failureReason`.

### US-3 — Watch it happen, and come back to it

> As a **user waiting ~30 seconds**, I want to see per-song progress and be able to leave, so that the
> wait feels accounted for and nothing is lost if I close the app.

**Acceptance criteria**

- **AC-3.1** `GET /api/playlist-generation-jobs/{id}` returns `state`, `currentStage`,
  `songsProcessed`, `songsTotal`, `estimatedSecondsRemaining`, `blockedReason`, `resumableAfter` and,
  once terminal, the playlist id.
- **AC-3.2** The response carries `Retry-After: 1` while `matching`/`building`, `3` while
  `queued`/`resolving_setlist`, and **no** `Retry-After` on a terminal, blocked or suspended state.
- **AC-3.3** The response carries an `ETag`; an unchanged poll returns **304** with no body.
- **AC-3.4** `songsProcessed` advances per song, in its own short transaction, and survives a worker
  kill — a job restarted from a crash reports where it actually got to.
- **AC-3.5** Closing the client mid-generation changes nothing server-side; the completed result is
  there on the next read.

### US-4 — Retry without fear

> As a **user whose generation blocked or failed**, I want "try again" to be safe, so that I never end
> up with two playlists or a doubled track list.

**Acceptance criteria**

- **AC-4.1** `POST /api/playlist-generation-jobs/{id}/retry` on a `failed` job re-enters the **same
  row** with `attempt++` and the same `idempotencyKey`.
- **AC-4.2** A retry after the provider playlist was created **reuses** `providerPlaylistId` and never
  calls `createPlaylist()` again.
- **AC-4.3** A retry after partial insertion resumes at `insertedThroughOrdinal` and re-sends no
  earlier batch.
- **AC-4.4** A retry re-resolves **no** song already in `TrackResolution` under the same
  `algorithmVersion` — a resumed run's provider spend is only what remains.
- **AC-4.5** The one indeterminate case (`creationAttemptedAt` set, `providerPlaylistId` null) lands
  in `failed`/`creation_indeterminate` and offers `POST …/create-anyway`, which clears the marker and
  re-queues. It **never** creates silently. *(P-3, accepted.)*

### US-5 — Know whether it is working, on the first day

> As the **operator**, I want generation time and match quality on a backoffice screen the day this
> ships, so that "is this good?" is a query rather than an opinion.

**Acceptance criteria**

- **AC-5.1** `PlaylistGenerationJobCrudController` lists every job across owners, read-only, with
  filters on state, provider, mode, `blockedReason` and `failureReason`; the detail view shows the
  full `PlaylistTrack` table with outcome, confidence and reason code, plus block/failure detail and
  `stageTimings`.
- **AC-5.2** The dashboard gains a **"Playlist generation (last 7 days)"** panel: jobs started /
  completed / blocked / failed, **p50 and p95** generation time, **mean match rate**, the `not_found`
  rate, the `blockedReason` breakdown, and the **five most frequently unmatched `(artist, title)`
  pairs**.
- **AC-5.3** The panel flags p95 > **90 s**, a 7-day match rate < **0.75**, or a `blocked` share
  > **10 %** as investigate-thresholds.
- **AC-5.4** Neither controller offers a write action of any kind, and no admin read touches a query
  extension used by the public API.

### US-6 — Add Normal mode and a second provider without a rewrite

> As the **engineer implementing prompt 17 or 18**, I want the seams already present, so that I add a
> guard and a config file rather than a parallel implementation.

**Acceptance criteria**

- **AC-6.1** `JobState` already contains `awaiting_setlist_choice` and `awaiting_version_choice`, and
  `JobStateMachine` already permits T-04/T-05/T-07/T-08/T-19; Fast mode simply never takes them.
- **AC-6.2** `PlaylistPipeline::run()` is the single entry point, and the mode is a column read at
  exactly two guards.
- **AC-6.3** `App\Service\Playlist\` and `App\Service\Matching\` pass a static provider-symbol scan.
- **AC-6.4** Per-provider matching calibration is a `profiles.yaml` entry keyed by a runtime string;
  adding a provider requires no PHP change outside its own adapter directory.

---

## Technical Approach

### 0. Component shape

Two new service namespaces, one message, one handler, three entities plus the resolution cache, two
commands, and the API/admin surfaces. Nothing else moves.

```
backend/src/Service/Matching/                 ← NEW (spec 12), provider-agnostic, no provider symbol
  SongNormalizer.php                          §1 — Song title → NormalizedSong
  Model/NormalizedSong.php · Qualifier.php · MatchOutcome.php · MatchResult.php
  Similarity/TitleSimilarity.php · ArtistSimilarity.php
  MatchConfidence.php · MatchProfile.php · NonSongClassifier.php
  TrackMatcher.php                            the only public entry point
  Cache/TrackResolutionStore.php              Redis read-through over the Doctrine table
  README.md                                   the provider-free rule, in the shape of the existing service READMEs

backend/src/Service/Playlist/                 ← NEW (spec 13), provider-agnostic, no provider symbol
  PlaylistPipeline.php                        the ordered stages; the ONE entry point both modes use
  Stage/PreflightStage.php · SetlistSelectionStage.php · MatchingStage.php
       ReviewStage.php · CreationStage.php · InsertionStage.php · ReportStage.php
  JobStateMachine.php                         the ONLY class allowed to write PlaylistGenerationJob::$state
  JobProgressWriter.php                       the per-song counter, its own transaction
  GenerationEstimator.php                     rolling p50 per-song time → estimatedSecondsRemaining
  Model/JobState.php · JobMode.php · BlockedReason.php · FailureReason.php
       ResultKind.php · ReportCode.php · SelectedSetlist.php · MatchOutcomeCounters.php
  Exception/SetlistBudgetExhaustedException.php · NoSetlistsAvailableException.php
            PlaylistCreationIndeterminateException.php · JobExpiredException.php
            GenerationBlockedException.php
  Naming/PlaylistNamer.php
  README.md

backend/src/Message/BuildPlaylistMessage.php          { jobId, attempt } and nothing else (D-125)
backend/src/MessageHandler/BuildPlaylistHandler.php   loads, locks, delegates to PlaylistPipeline

backend/src/Entity/Playlist.php · PlaylistTrack.php · PlaylistGenerationJob.php · TrackResolution.php
backend/src/Repository/PlaylistRepository.php · PlaylistTrackRepository.php
                        PlaylistGenerationJobRepository.php · TrackResolutionRepository.php

backend/src/Security/PlaylistOwnerExtension.php · PlaylistGenerationJobOwnerExtension.php
backend/src/Security/Voter/PlaylistVoter.php · PlaylistGenerationJobVoter.php

backend/src/ApiResource/Playlist/…                    §6
backend/src/State/Provider/Playlist/… · State/Processor/Playlist/…

backend/src/Controller/Admin/PlaylistGenerationJobCrudController.php · PlaylistCrudController.php
backend/src/Service/Admin/PlaylistGenerationMetrics.php     the dashboard panel's SQL, read-only

backend/src/Command/ExpireSuspendedJobsCommand.php    app:playlist:expire-jobs   (nightly)
backend/src/Command/ResumeBlockedJobsCommand.php      app:playlist:resume-blocked (every 5 min)

backend/config/matching/profiles.yaml · non_song_terms.yaml
backend/config/packages/messenger.yaml                rewritten (§9)
backend/migrations/VersionYYYYMMDDHHMMSS.php          one migration, four tables (§2)
```

Inside the reference adapter directory (D-82 unchanged):

```
backend/src/Service/Streaming/Spotify/
  SpotifyTrackMapper.php      LOSES naiveConfidence() entirely (D-147); GAINS population of
                              TrackCandidate's generic signal fields (D-119)
  SpotifyQueryBuilder.php     NEW — builds the provider's search string from a SongQuery
```

`TrackMatcher` and `PlaylistPipeline` are single doors, mirroring `SetlistGateway` (D-58) for the
same reason: a rule is only as strong as its weakest caller.

---

### 1. Identity, and the migration plan

**D-146 — the four new tables use the project's existing integer surrogate identity, not the UUIDs
the spikes sketched.** Both spikes wrote `id uuid` in their entity sketches. Every entity in
`backend/src/Entity/` today — `Concert`, `Band`, `Setlist`, `Song`, `StreamingAccount`,
`ProviderSetting` — uses `#[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column(type: 'integer')]`. Introducing
a second identity convention for four tables would mean two `ConcertLocator` shapes, two URL patterns
in the OpenAPI spec, and a `ramsey/uuid`-shaped dependency question that no other part of the
codebase has had to answer.

Enumerability is not the objection it looks like: `Concert` is already an enumerable integer id behind
`ConcertOwnerExtension`'s cross-owner 404, and these two resources copy that gate exactly (D-157). The
one place spec 13 leaned on a UUID — F-14's *"find the possible orphan in your account"* marker — is
served by `Setlistify job #<id>` in the playlist description, which is equally findable.

*This is a divergence from spec 13 §2 and spec 12 §8 and is written back into both in the same
branch.* It is additive-free: no behaviour in either spike depends on the identity type.

#### `PlaylistGenerationJob` — table `playlist_generation_jobs`

| Column | PostgreSQL type | Null | Notes |
|---|---|---|---|
| `id` | `SERIAL` PK | no | D-146 |
| `owner_id` | `INT` FK → `users` | no | `ON DELETE CASCADE`. User-scoped: 404 not 403 (D-157) |
| `concert_id` | `INT` FK → `concerts` | no | `ON DELETE CASCADE` — T-18 via cascade |
| `provider_key` | `VARCHAR(32)` | no | `StreamingProviderInterface::key()`, a runtime string |
| `streaming_account_id` | `INT` FK → `streaming_accounts` | no | `ON DELETE CASCADE` |
| `mode` | `VARCHAR(16)` | no | `enumType: JobMode` — `fast` \| `normal` |
| `state` | `VARCHAR(32)` | no | `enumType: JobState` — the eleven of spec 13 §1 |
| `idempotency_key` | `CHAR(64)` | no | sha256 hex (§5) |
| `attempt` | `SMALLINT` | no | default 1; incremented by T-16 only |
| `algorithm_version` | `SMALLINT` | no | copied from the active `MatchProfile` at queue time |
| `candidate_setlists` | `JSONB` | yes | Normal-mode suspension payload; always `NULL` in Fast mode |
| `selected_setlists` | `JSONB` | yes | `[{bandId, setlistfmId, selectionReason, fingerprint, songCount}]` |
| `pending_choices` | `JSONB` | yes | Normal-mode suspension payload |
| `user_choices` | `JSONB` | yes | kept through expiry, for pre-filling a new job |
| `songs_total` | `INT` | no | default 0 |
| `songs_processed` | `INT` | no | default 0 — the polling counter |
| `current_stage` | `VARCHAR(24)` | yes | `enumType: PipelineStage` |
| `stage_entered_at` | `TIMESTAMPTZ` | yes | |
| `blocked_reason` | `VARCHAR(32)` | yes | `enumType: BlockedReason` — six cases |
| `resumable_after` | `TIMESTAMPTZ` | yes | `NULL` when the unblock is a human action (F-06) |
| `blocked_at_stage` | `VARCHAR(24)` | yes | |
| `block_cycle_count` | `SMALLINT` | no | default 0; `> MAX_BLOCK_CYCLES` → T-14 |
| `blocked_ms` | `INT` | no | default 0 — accumulated so a 9-hour wait never poisons p95 |
| `failure_reason` | `VARCHAR(32)` | yes | `enumType: FailureReason` — three cases |
| `failure_detail` | `JSONB` | yes | a code and parameters, **never a stack trace** |
| `result_kind` | `VARCHAR(24)` | yes | `enumType: ResultKind` |
| `matched_count` · `low_confidence_count` · `not_found_count` · `skipped_count` · `region_restricted_count` | `INT` | no | default 0, frozen at completion |
| `mean_confidence` | `REAL` | yes | over matched + low-confidence rows |
| `duration_ms` | `INT` | yes | excludes blocked intervals |
| `stage_timings` | `JSONB` | yes | `{preflight, selection, normalize, matching, create, insert, report}` in ms |
| `created_at` · `started_at` · `finished_at` · `suspended_at` · `expires_at` · `updated_at` | `TIMESTAMPTZ` | `created_at`/`updated_at` no, rest yes | `updated_at` is **new relative to spec 13** and exists solely to make §6's `ETag` cheap (D-150) |

Indexes and constraints:

```sql
CREATE UNIQUE INDEX uniq_live_generation
  ON playlist_generation_jobs (concert_id, provider_key)
  WHERE state IN ('queued','resolving_setlist','awaiting_setlist_choice',
                  'matching','awaiting_version_choice','building','blocked');

CREATE UNIQUE INDEX uniq_live_generation_per_user
  ON playlist_generation_jobs (owner_id)
  WHERE state IN ('queued','resolving_setlist','matching','building');   -- D-144 anti-starvation

CREATE INDEX idx_pgj_state_resumable  ON playlist_generation_jobs (state, resumable_after);
CREATE INDEX idx_pgj_state_expires    ON playlist_generation_jobs (state, expires_at);
CREATE INDEX idx_pgj_owner_concert    ON playlist_generation_jobs (owner_id, concert_id);
CREATE INDEX idx_pgj_created_at       ON playlist_generation_jobs (created_at);  -- the 7-day panel
```

Both partial unique indexes are emitted as raw `addSql()` in the migration — Doctrine's
`#[ORM\UniqueConstraint]` cannot express a `WHERE` clause, and the constraint is the mechanism, not a
convention (AC-3.4 of spec 13). The anti-starvation index deliberately **excludes** `blocked` and the
two `awaiting_*` states: a user with a job waiting for tomorrow's quota must still be able to start a
different one.

#### `Playlist` — table `playlists`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `SERIAL` PK | no | |
| `owner_id` | `INT` FK → `users` | no | `ON DELETE CASCADE` |
| `concert_id` | `INT` FK → `concerts` | no | `ON DELETE CASCADE` — architecture §10's `Concert ──< Playlist` |
| `job_id` | `INT` FK → `playlist_generation_jobs` | no | the job that produced it; `ON DELETE CASCADE` |
| `provider_key` | `VARCHAR(32)` | no | |
| `provider_playlist_id` | `VARCHAR(128)` | **yes** | **the creation marker** — `NULL` until confirmed (D-136) |
| `creation_attempted_at` | `TIMESTAMPTZ` | yes | written and committed **before** `createPlaylist()` |
| `external_url` | `TEXT` | yes | from `ProviderPlaylist::$externalUrl` |
| `name` | `VARCHAR(200)` | no | `PlaylistNamer` output (D-140) |
| `description` | `TEXT` | yes | carries `Setlistify job #<id>` |
| `inserted_through_ordinal` | `INT` | no | default 0 — **the insertion watermark** (D-137) |
| `report_summary` | `JSONB` | no | job-level `{code, params}` entries (D-141) |
| `created_at` · `updated_at` | `TIMESTAMPTZ` | no | |

```sql
CREATE INDEX idx_playlists_owner_concert ON playlists (owner_id, concert_id);
CREATE INDEX idx_playlists_job           ON playlists (job_id);
```

#### `PlaylistTrack` — table `playlist_tracks`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `SERIAL` PK | no | |
| `playlist_id` | `INT` FK → `playlists` | no | `ON DELETE CASCADE` |
| `ordinal` | `INT` | no | dense, 0-based position among **all** source songs, in playing order |
| `source_song_id` | `INT` FK → `songs` | yes | `ON DELETE SET NULL` — a purged setlist must not delete the report |
| `source_band_id` | `INT` FK → `bands` | no | denormalized for multi-band ordering and the report |
| `source_setlistfm_id` | `VARCHAR(64)` | no | denormalized, survives a cache purge |
| `source_position` | `INT` | no | `Song::$position`, **preserved even when unmatched** (D-140) |
| `source_title` | `VARCHAR(200)` | no | denormalized, so the report is readable after a purge |
| `segment_index` | `SMALLINT` | **yes** | medley segments (D-114, spec 12 §5) |
| `provider_track_id` | `VARCHAR(128)` | yes | |
| `confidence` | `REAL` | yes | |
| `outcome` | `VARCHAR(32)` | no | `enumType: TrackOutcome` — `pending` \| `matched` \| `matched_low_confidence` \| `skipped` \| `not_found` \| `region_restricted` |
| `reason_code` | `VARCHAR(48)` | yes | `enumType: ReportCode` (D-141) |
| `reason_params` | `JSONB` | yes | e.g. `{"artist": "Nine Inch Nails"}` |
| `inserted_at` | `TIMESTAMPTZ` | yes | `NULL` until its batch is confirmed (D-137) |

```sql
CREATE UNIQUE INDEX uniq_playlist_track_ordinal ON playlist_tracks (playlist_id, ordinal);
CREATE INDEX idx_playlist_tracks_source        ON playlist_tracks (playlist_id, source_position);
```

**Every song in the source setlist gets a row, including the ones that produce no track** —
`docs/architecture.md` §10 says so, spec 12 §5 depends on it, and D-139 makes it the mechanism by
which partial success is storable at all. `ordinal` is the position in *our* ordered list;
`source_position` is the position in the show; the *inserted* sequence is the matched subsequence of
`ordinal`. The divergence between them **is** the report.

The four denormalized `source_*` columns are an addition over spec 13's sketch and are deliberate: a
report that becomes unreadable when a `Setlist` row is purged is not an honest report.

#### `TrackResolution` — table `track_resolutions` (spec 12 §8, D-121)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `SERIAL` PK | no | |
| `provider` | `VARCHAR(32)` | no | `key()`, a runtime string |
| `algorithm_version` | `SMALLINT` | no | bumped by any normalizer / formula / threshold / lexicon change |
| `normalized_title` | `VARCHAR(200)` | no | `SongNormalizer`'s `comparisonCore` |
| `normalized_artist` | `VARCHAR(200)` | no | `BandResolver::normalize()` of the **expected** artist (D-113) |
| `provider_track_id` | `VARCHAR(128)` | yes | **`NULL` = a cached negative result** |
| `confidence` | `REAL` | no | the winning candidate's score |
| `outcome` | `VARCHAR(32)` | no | `matched` \| `matched_low_confidence` \| `not_found` |
| `candidates_digest` | `JSONB` | no | top 5 candidates + sub-scores, for the harness and the backoffice |
| `resolved_at` · `expires_at` | `TIMESTAMPTZ` | no | TTLs: 180 d / 60 d / 30 d by outcome |

```sql
CREATE UNIQUE INDEX uniq_track_resolution
  ON track_resolutions (provider, algorithm_version, normalized_artist, normalized_title);
CREATE INDEX idx_track_resolutions_expires ON track_resolutions (expires_at);
```

`market`/region is **deliberately not in the key** (spec 12 §8): which recording *is* the song does
not depend on where the asker stands; whether they may play it does, and that is discovered at insert
time as a per-user `region_restricted` outcome.

#### Migration

One versioned migration, generated with `doctrine:migrations:diff` and then hand-edited to add the
two partial unique indexes and the `ON DELETE` behaviours Doctrine's diff does not infer. Order:
`track_resolutions` → `playlist_generation_jobs` → `playlists` → `playlist_tracks` (FK order). A
`down()` drops them in reverse. **`doctrine:schema:update` is not used**, per `backend/README.md`.
Enum columns are `VARCHAR` with `enumType:` on the PHP side, never native PostgreSQL enums — adding
a state must be a code change, not a migration (which is what makes prompt 17's two extra states
free).

---

### 2. Matching implementation — spec 12, made real

Implemented exactly as spec 12 specifies. This section restates only what an implementer needs to
resolve without re-reading it, and adds nothing.

| Piece | Class | Contract |
|---|---|---|
| Normalization | `App\Service\Matching\SongNormalizer` | `normalize(string $rawTitle): NormalizedSong`. The ordered transforms **N0–N8** of spec 12 §1: trim/collapse → NFKD + strip `\p{Mn}` → ligature fold (`æ→ae`, `ø→o`, `ß→ss`, `ð→d`, `þ→th`, `ł→l`, `đ→d`) → case fold → punctuation unification (`’→'`, `–→-`, `&→and`) → **parenthetical extraction, not stripping** → positional featured-credit stripping → **leading articles kept** → strip remaining non-alphanumerics → re-collapse. Runs on **both** sides after the response arrives; the **raw** title is what goes to the provider (D-107) |
| Qualifier classification | `App\Service\Matching\Model\Qualifier` | `Version` \| `FeaturedCredit` \| **`TitleContinuation` (the default)** — an unrecognized parenthetical returns to the core (D-108) |
| Title similarity | `App\Service\Matching\Similarity\TitleSimilarity` | `0.60 · Dice₃ + 0.40 · WeightedJaccard`, with exact/qualifier-aware short-circuits above it. **Trigrams over code points via `mb_str_split`, never bytes.** Stop tokens weigh 0.25 |
| Artist similarity | `App\Service\Matching\Similarity\ArtistSimilarity` | Wraps `App\Service\Concert\BandResolver::normalize()` **verbatim** and adds nothing (D-106). Returns the 1.00 / 0.90 / 0.85 / 0.60 / 0.00 ladder |
| Confidence | `App\Service\Matching\MatchConfidence` | The weighted sum **renormalized over present signals**, plus the **artist gate**: `conf = min(raw, 0.45)` when `s_artist < 0.50` (D-109). Signals and weights per spec 12 §3; duration is defined and normally absent, so the usual denominator is 0.92 |
| Profile | `App\Service\Matching\MatchProfile` | Weights, `titleBlend`, thresholds, and the optional duration plausibility band, per provider key |
| Non-song detection | `App\Service\Matching\NonSongClassifier` | `Song::isTape()` → curated **whole-title exact** lexicon disambiguated by `position`/`setLabel` → an advisory-only third signal that **never** promotes a miss into a skip (D-116) |
| The cascade | `App\Service\Matching\TrackMatcher` | Tier 0 pre-filter → Tier 1 cache → **one** `searchTrack()` → Tiers 3–5 comparison → band → persist the resolution. **One search per song. No speculative second search** (D-120) |
| Resolution cache | `App\Service\Matching\Cache\TrackResolutionStore` | Redis read-through (300 s) over the `track_resolutions` table, with promotion on a durable hit — the same shape `App\Service\Setlist\SetlistCache` already uses. Deletes a row on `NotFoundException` at insert time (the one required runtime invalidation) |

**Thresholds and where they live.** `backend/config/matching/profiles.yaml`, bound as container
parameters, keyed by `StreamingProviderInterface::key()` — **not** `ProviderSetting`, **not** the
backoffice (D-110, confirmed on spec 12's approval). The initial values are spec 12's:

```yaml
matching:
  algorithmVersion: 1
  default:
    weights: { title: 0.40, artist: 0.25, version: 0.12, duration: 0.08,
               releaseType: 0.06, authority: 0.05, popularity: 0.02, rank: 0.02 }
    titleBlend: { trigram: 0.60, tokenSet: 0.40 }
    thresholds: { autoAccept: 0.80, choice: 0.55, artistGateFloor: 0.50, artistGateCap: 0.45 }
    durationPlausibility: { enabled: false }
  profiles: {}          # per-provider overrides only; keys are runtime strings
```

`MATCHING_AUTO_ACCEPT_THRESHOLD` and `MATCHING_CHOICE_THRESHOLD` exist as env overrides and are
documented in `docs/env-vars.md` **in those words**: an operational escape hatch, not a tuning
mechanism. A deliberate threshold change is a pull request with before/after harness numbers, and it
**must** bump `matching.algorithmVersion`.

**D-148 — `algorithmVersion` is a configuration value read at queue time, and bumping it is part of
the definition of a matching change.** It is copied onto every `PlaylistGenerationJob` row so a
before/after comparison is a `GROUP BY`, and it is part of the `TrackResolution` unique key so two
calibrations never mix. Old-version rows are **kept** (that is what lets the harness diff two
calibrations over one corpus); `app:playlist:expire-jobs` also prunes resolution rows more than one
version behind.

**D-147 — `SpotifyTrackMapper::naiveConfidence()` is deleted, not deprecated.** D-83 called it
*deliberately provisional* and named prompt 12 as its replacement; spec 12 §2 gives the concrete
reason (`levenshtein()` is byte-based, so `'sigur rós'` vs `'sigur ros'` scores 2 rather than 1, and
the denominator is in bytes too). Its removal is a three-part change, all inside the adapter
directory:

1. `naiveConfidence()` and its call site are removed; `TrackCandidate::$confidence` is populated with
   the provider's own result-rank-derived placeholder **only** as an ordering hint, and is
   **never read by `MatchConfidence`** — the scorer computes its own number from the signal fields.
2. `SpotifyTrackMapper` gains the job of populating `TrackCandidate`'s new provider-agnostic signal
   fields (D-119): `artistAuthority` (enum `Official`/`Verified`/`Unknown`), `albumType` (nullable
   enum), `popularity` (`?float` 0–1), `isrc` (`?string`, carried, not consumed) and `providerRank`
   (`int`).
3. `SpotifyQueryBuilder` is extracted so query construction — including the `market` parameter — has
   one home inside the adapter.

`TrackCandidate` gaining fields does **not** reopen D-71: the port keeps its nine methods, and
`TrackCandidate` is a shared value object outside every adapter whose new fields are generic concepts
any provider can answer or leave null (D-119). Existing callers construct it positionally; the new
fields are appended with defaults so no call site outside the adapter changes.

---

### 3. `BuildPlaylistHandler` and the pipeline

#### The message and the handler

```
App\Message\BuildPlaylistMessage      final readonly { public int $jobId, public int $attempt }
App\MessageHandler\BuildPlaylistHandler   #[AsMessageHandler]
```

The message carries **an id and an attempt number and nothing else** (D-125). Every other input is a
column, which is what makes a redelivered message idempotent and what keeps a serialized message from
outliving the truth.

The handler's five steps, in order:

1. **Acquire `symfony/lock` on `playlist-job-<id>`** (non-blocking; a failure to acquire means the
   run is already in flight — the handler returns, satisfying T-20). Same pattern
   `StreamingTokenManager` already uses per account (D-79).
2. **Re-read the job row inside the lock.** A terminal or suspended state means the message is stale:
   return without work.
3. **Delegate to `PlaylistPipeline::run(PlaylistGenerationJob $job): void`.** The handler contains no
   business logic — it is a lock, a load and a call.
4. **Catch `GenerationBlockedException`** → `JobStateMachine::block($job, $reason, $resumableAfter,
   $stage)`; the message is **acknowledged**, not retried, because a blocked job is resumed by the
   sweeper, not by Messenger.
5. **Catch `\Throwable`** → rethrow so Messenger's retry policy applies; on the last attempt, the
   failure transport receives it and a `RecoverableFailureListener` moves the job to `blocked`
   (`upstream_unavailable`) or, past `MAX_BLOCK_CYCLES`, to `failed` (T-15).

#### The stages, in order

Each stage is a class in `App\Service\Playlist\Stage\`, called by `PlaylistPipeline::run()`. **Every
stage boundary re-checks `ProviderRegistry::isAvailable($job->getProviderKey())`** before proceeding
— that is what makes "a provider disabled at song 12 stops the job at song 12" true (D-134/F-07).
**Every state entry is its own committed transaction, and no provider call happens inside an open
transaction.**

| # | Stage | Does | Writes on entry / exit |
|---|---|---|---|
| 1 | `PreflightStage` | Provider enabled? Adapter registered? `StreamingAccount` `connected`? Concert owned? No live job? | `state = resolving_setlist`, `startedAt`, `stageEnteredAt` |
| 2 | `SetlistSelectionStage` | Per band, in **stage order** (P-2): resolve setlist.fm identity via `BandIdentityResolver`, read **cached** `Setlist` rows, apply **most recent substantial** (D-132), spend at most **one** index page per band if nothing is cached (D-131). Apply `GENERATION_MAX_BANDS` / `GENERATION_MAX_SONGS` (P-1), cutting from the lowest-billed end, emitting `BANDS_OMITTED_FOR_LENGTH` / `SETLIST_TRUNCATED` | `selectedSetlists`, `songsTotal`, and **the ordered `PlaylistTrack` skeleton — one row per source song, `outcome = pending`, created up front** (D-140). Then `state = matching` |
| 3 | *(normalize, inside stage 4)* | `SongNormalizer` over every source `Song` | — |
| 4 | `MatchingStage` | `TrackMatcher::match()` per song, cache-first, sequential. Writes each song's `PlaylistTrack` row and `songsProcessed++` in **one small transaction per song** via `JobProgressWriter` | On exit: `matchingFinishedAt`, frozen counters, `state = building` |
| 5 | `ReviewStage` | **Fast mode: a no-op.** The `CHOICE` band is *included and flagged* as `matched_low_confidence` (spec 12, resolved Q1). Normal mode's guard — `mode === JobMode::Normal && choiceBand !== []` → `awaiting_version_choice` — is present and unreachable in this feature | — |
| 6 | `CreationStage` | Commit `creationAttemptedAt`, call `createPlaylist(PlaylistDraft, ProviderTokens)`, commit `providerPlaylistId` + `externalUrl` | The creation marker (§5) |
| 7 | `InsertionStage` | `addTracks()` over contiguous slices of `INSERT_BATCH_SIZE = min(50, provider maximum)`, advancing `insertedThroughOrdinal` **only after** each call returns | The watermark (§5) |
| 8 | `ReportStage` | Freeze counters, `meanConfidence`, `durationMs` (excluding `blockedMs`), `stageTimings`, `resultKind`, `reportSummary` | `state = completed`, `finishedAt` |

#### Match everything first, create the playlist last — D-135, and why it is not negotiable here

`CREATE` sits after `MATCH`. That is not the obvious order and it is the single most consequential
ordering decision in the feature:

1. A quota-exhausted provider throws `QuotaExhaustedException` at **song 1**, long before anything
   exists in the user's account — which is the "refuse upfront" behaviour without a tenth port method.
2. `no_source_material` (T-10) and `no_tracks_matched` (T-09) create **nothing**, so a band with no
   setlist.fm data never litters someone's library with an empty list named after a concert.
3. The irreversible step is entered only once it is known to be worth entering, which shrinks F-14's
   unclosable window to the narrowest slice of the run.
4. The expensive phase writes only our own rows, and `TrackResolution` has already banked every
   provider call it spent — so a crash mid-match costs CPU and nothing else.

The cost — the user's Spotify library shows nothing until ~90 % of the wall clock has elapsed — is
irrelevant: they are watching our progress screen, not their library.

#### Provider tokens

Every provider call is preceded by `StreamingTokenManager::usableTokens($account)`. The pipeline
**never** calls `refreshToken()` (D-79), never persists a token, and never logs one. A
`TokenExpiredException` from `usableTokens()` means D-80 has *already* flipped the account to
`needs_reauth`; the pipeline's only job is to block with `needs_reauth` and `resumableAfter = null`.

---

### 4. Outcomes and the report

The five per-song outcomes are spec 12's, plus `pending` as the skeleton state:

| `PlaylistTrack.outcome` | Reached when | Report code | In the match-rate denominator? |
|---|---|---|---|
| `pending` | Row created, not yet matched | — | n/a (transient) |
| `matched` | `conf ≥ 0.80` | `COVER_OF` / `LIVE_VERSION_ONLY` where applicable, else none | yes, as a hit |
| `matched_low_confidence` | `0.55 ≤ conf < 0.80` — **included and flagged** | `LOW_CONFIDENCE_MATCH` | yes, as a hit |
| `skipped` | `isTape` or the non-song lexicon | `TAPE_NOT_PERFORMED` / `PERFORMANCE_ARTIFACT` | **no** — a drum solo is neither a hit nor a miss |
| `not_found` | Zero candidates, or best `conf < 0.55`, or F-13 | `TRACK_NOT_IN_CATALOG` / `TRACK_VANISHED` | yes, as a miss |
| `region_restricted` | `RegionRestrictedException` at insert | `NOT_AVAILABLE_IN_REGION` | yes, as a miss |

**The report is stored, not computed at read time, and it is codes and parameters, never rendered
English** (D-141):

```json
{ "code": "COVER_OF", "params": { "artist": "Nine Inch Nails" } }
{ "code": "BANDS_OMITTED_FOR_LENGTH", "params": { "bands": ["…","…"] } }
```

Per-song codes live on `PlaylistTrack.reason_code` + `reason_params`; job-level codes live in
`Playlist.report_summary`. Prompt 15 designs the sentences and prompt 16 renders them, so the wording
can change — and be translated — without a migration.

The job-level codes this feature emits: `NO_SETLIST_FOR_BAND`, `SETLIST_MAY_BE_STALE`,
`SELECTED_FROM` (with setlist date, venue, song count and `selectionReason`), `BANDS_OMITTED_FOR_LENGTH`,
`SETLIST_TRUNCATED`, `RESUMED_MID_INSERTION`, `FALLBACK_LONGEST_SETLIST`.

**`selectionReason` is rendered on every playlist** — `most_recent_substantial` \| `fallback_longest`
\| `only_one_available` \| `user_chosen` (D-132). A default this opinionated must be visible.

---

### 5. Failure handling and idempotency

#### Every failure mode → a state transition

All sixteen of spec 13 §4, with the class that implements each. **Nowhere is there a bare
`catch (\Throwable)` inside a stage.**

| # | Trigger | Caught in | Lands in | Recovery |
|---|---|---|---|---|
| F-01 | `CachedFetch->reason === 'budget_exhausted'` **and** nothing cached for the band | `SetlistSelectionStage` | `blocked` / `setlistfm_budget`, `resumableAfter = $fetch->budgetResetAt` | Automatic, at the UTC reset |
| F-02 | `BandIdentityResolver` → `no_presence` \| `ambiguous` | `SetlistSelectionStage` | `completed` / `no_source_material` (or partial on a multi-band bill) | none needed |
| F-03 | Every candidate `Setlist::isEmpty()` or `songCount === 0` | `SetlistSelectionStage` | `completed` / `no_source_material` | none |
| F-04 | `QuotaExhaustedException` from `searchTrack()` or `addTracks()` | `MatchingStage` / `InsertionStage` | `blocked` / `provider_quota`, `resumableAfter` = the adapter-declared next window | Automatic |
| F-05 | `RateLimitedException` (`retryAfterSeconds`) | the calling stage | **stays active** for `RATE_LIMIT_INLINE_RETRIES = 3`, sleeping `min(retryAfterSeconds, 30)`; then `blocked` / `provider_rate_limit`, `+15 min` | Automatic |
| F-06 | `TokenExpiredException` from `usableTokens()` | any stage | `blocked` / `needs_reauth`, `resumableAfter = null` | **Manual** — re-queued when the account returns to `connected` |
| F-07 | `ProviderRegistry::isAvailable()` false at a stage boundary, or `ProviderDisabledException` | `PlaylistPipeline` boundary check | `blocked` / `provider_disabled`, `+30 min` | Automatic on re-enable |
| F-08 | A batch throws with `insertedThroughOrdinal < count` | `InsertionStage` | `blocked` with the causing reason — **never `failed`** | Automatic, from the watermark |
| F-09 | Zero candidates or best `conf < 0.55` | `TrackMatcher` | **no state change** | per-track `not_found` |
| F-10 | Every candidate carries a Version qualifier | `MatchConfidence` (signal 3 renormalizes away) | no state change | `matched`, `LIVE_VERSION_ONLY` |
| F-11 | `RegionRestrictedException` at insert | `InsertionStage` | no state change | per-track `region_restricted`; **the `TrackResolution` row is NOT invalidated** |
| F-12 | `ProviderUnavailableException` or a transport `\Throwable` | rethrown to Messenger | 3 retries (5 s / 30 s / 180 s, 20 % jitter), then `blocked` / `upstream_unavailable` `+15 min`; past `MAX_BLOCK_CYCLES = 3` → `failed` | Automatic, then manual |
| F-13 | `NotFoundException` at `addTracks()` | `InsertionStage` | no state change | per-track `not_found`; **the `TrackResolution` row is deleted** — the one required runtime invalidation |
| F-14 | On entering `building`: `creationAttemptedAt != null && providerPlaylistId == null` | `CreationStage` | **`failed`** / `creation_indeterminate` | **Explicit user action** — `POST …/create-anyway` *(P-3)* |
| F-15 | `UnknownProviderException` from the locator | `PreflightStage` | **`failed`** / `unknown_provider` | none — a deployment defect |
| F-16 | `app:playlist:expire-jobs` finds a suspended job past TTL | the command | `expired` | a new job, pre-filled from `userChoices` |

**Three routes to `failed` and no others**: F-14, F-15, and a block cycle count above 3. "Some songs
were missing" is not among them. That is what "degrades, does not fail" means when it is written as a
state machine rather than an aspiration.

`JobStateMachine` is the **only** class permitted to assign `PlaylistGenerationJob::$state`, and an
illegal edge raises `\LogicException` — a bug, never a user-facing error. **D-159** makes that
structural: `JobStateMachineIsOnlyStateWriterTest` scans `backend/src/` for any other assignment,
the same technique as `SetlistGatewayIsOnlyDoorTest`.

#### The three idempotency levels

**Level 1 — the partial unique index** (`uniq_live_generation`). A double-tapped button, a retried
request or two devices collide on the database, not on a service-layer check with a race in it.
`StartGenerationProcessor` catches `UniqueConstraintViolationException` and returns **the existing
job with 200** — never a second job, never a 409. Starting a generation is idempotent from the
client's point of view, which is what an unreliable mobile network needs (D-129).

`idempotencyKey = sha256(concertId | providerKey | mode | algorithmVersion | sourceFingerprint)` is
**not** the uniqueness mechanism — it is the *equality* mechanism, telling a resumed run whether it
is the same generation, and it is what T-16 preserves so a retry re-enters the same row.

**Level 2 — the creation marker.**

```
1. COMMIT  playlists.creation_attempted_at = now()      ← before any network call
2. CALL    StreamingProviderInterface::createPlaylist(PlaylistDraft, ProviderTokens)
3. COMMIT  playlists.provider_playlist_id = <id>, external_url = <url>
```

| `creation_attempted_at` | `provider_playlist_id` | Action |
|---|---|---|
| `NULL` | `NULL` | Create |
| set | set | **Skip creation, reuse the id** — the ordinary retry path |
| set | `NULL` | **F-14: stop. Do not create.** *(P-3)* |

**Level 3 — the insertion watermark.** `insertedThroughOrdinal` advances only after the provider call
returns. A resumed run starts at the watermark, so an earlier batch is never re-sent. The residual
window is exactly **one provider call wide**; when `attempt > 1 && insertedThroughOrdinal > 0` the job
sets `RESUMED_MID_INSERTION` so the report says so rather than letting the user discover it.

**Safe retry boundaries:** every stage is either purely local (`SELECT`, `NORMALIZE`, `MATCH`,
`REPORT` — writing only our rows, backed by `TrackResolution` so no provider call is re-spent) or
watermarked (`CREATE`, `INSERT`). There is no stage from which a retry is unsafe and no in-memory
state a retry depends on.

---

### 6. API surface

Four capabilities, seven operations. **The OpenAPI document generated from these resource classes is
the single source of truth** — this table exists to specify the change, not to duplicate the spec, and
**no endpoint is listed in any README**.

Every resource follows the project's established shape (D-22/D-29): an `#[ApiResource]` class with no
entity binding, dedicated `Output` DTOs, a state provider per read and a state processor per write,
`security: "is_granted('IS_AUTHENTICATED_FULLY')"` on every operation.

| Operation | Resource / provider / processor | Request | Success | Errors |
|---|---|---|---|---|
| `POST /api/playlist-generation-jobs` | `PlaylistGenerationJobResource` → `App\State\Processor\Playlist\StartGenerationProcessor` | `StartGenerationInput { concertId: int, provider?: string }` | **201** + `PlaylistGenerationJobOutput`; **200** + the existing job when one is live (D-129) | **404** unknown *or not owned* concert · **422** validation (`ConstraintViolation`) · **409** never · **503** `ProviderDisabledException` · **404** `UnknownProviderException` · **422** no `connected` `StreamingAccount` for the chosen provider |
| `GET /api/playlist-generation-jobs/{id}` | `PlaylistGenerationJobItemProvider` | — | **200** + output, `ETag`, `Retry-After`; **304** when unchanged | **404** for another owner's id, byte-identical to a missing id |
| `GET /api/playlist-generation-jobs` | `PlaylistGenerationJobCollectionProvider` | `?concertId=&state=` , paginated | **200** collection, owner-filtered | — |
| `POST /api/playlist-generation-jobs/{id}/retry` | `RetryGenerationProcessor` | empty | **202** + the job in `queued` (T-16) | **404** cross-owner · **422** when the job is not `failed` |
| `POST /api/playlist-generation-jobs/{id}/cancel` | `CancelGenerationProcessor` | empty | **202** + the job in `cancelled` (T-18) | **404** · **422** when already terminal |
| `POST /api/playlist-generation-jobs/{id}/create-anyway` | `CreateAnywayProcessor` | empty | **202** — clears `creationAttemptedAt`, re-queues *(P-3)* | **404** · **422** unless `failureReason = creation_indeterminate` |
| `GET /api/playlists/{id}` | `PlaylistItemProvider` | — | **200** + `PlaylistOutput` incl. every `PlaylistTrackOutput` and the report | **404** cross-owner |
| `GET /api/playlists` | `PlaylistCollectionProvider` | `?concertId=` | **200** collection, owner-filtered | — |
| `DELETE /api/playlists/{id}` | `PlaylistDeleteProcessor` | — | **204** | **404** cross-owner |

#### Response shapes

`PlaylistGenerationJobOutput` — deliberately flat, because it is polled twenty times per generation:

```
id · concertId · provider · mode · state · currentStage
songsTotal · songsProcessed · estimatedSecondsRemaining
blockedReason · resumableAfter · failureReason · resultKind
playlistId (null until a playlist exists) · matchedCount · lowConfidenceCount
notFoundCount · skippedCount · regionRestrictedCount
createdAt · startedAt · finishedAt
```

`PlaylistOutput`:

```
id · concertId · provider · name · description · externalUrl
state-derived resultKind · matchRate · createdAt
report: [ { code, params } ]                          ← job-level (D-141)
tracks: [ { ordinal, sourcePosition, segmentIndex, bandName, sourceTitle,
            providerTrackId, confidence, outcome, reasonCode, reasonParams } ]
```

Note what `PlaylistOutput` does **not** carry: no provider token, no raw candidate payload, no
`candidatesDigest`. Those are backoffice and harness data.

#### Ownership — 404, never 403 (D-157)

`App\Security\PlaylistOwnerExtension` and `App\Security\PlaylistGenerationJobOwnerExtension` are
copies of `App\Security\ConcertOwnerExtension`'s shape: both implement
`QueryCollectionExtensionInterface` and `QueryItemExtensionInterface`, both add
`WHERE <alias>.owner = :current_user` **before** any voter runs, and both write `1 = 0` for an
unauthenticated principal. `PlaylistVoter` and `PlaylistGenerationJobVoter` are the second gate for
any future path that reaches the entity outside a filtered query.

**`ConcertOwnerExtension` is not touched.** It is not made role-aware, gains no `ROLE_ADMIN` branch,
and is not parameterized. The backoffice reads across owners through Doctrine directly (§7), which is
D-47's rule and the reason that class stays a two-branch function.

#### Polling contract (D-150)

- `Retry-After: 1` while `matching` or `building`; `3` while `queued` or `resolving_setlist`;
  **absent** on `completed`, `failed`, `expired`, `cancelled`, `blocked`, and both `awaiting_*` —
  which is how a client knows to stop polling without special-casing eleven state names.
- `ETag: W/"<id>-<state>-<songsProcessed>-<updatedAt epoch>"`. A matching `If-None-Match` returns
  **304** with no body. The `updated_at` column exists for exactly this (§1's divergence note).
- The client polls every **1.5 s** while active. A 25-song generation is ~30 s ≈ **20 polls**, each
  one indexed primary-key read, most of them 304s.
- **A `blocked` job is never an HTTP error.** `GET` returns 200 with `blockedReason` and
  `resumableAfter`. Prompt 16's brief forbids rendering it red; the API must not tempt it.

#### Delete semantics — D-151

`DELETE /api/playlists/{id}` deletes **our** `Playlist` and its `PlaylistTrack` rows and returns 204.
It does **not** delete the playlist in the user's provider account, because the port has no delete
method and D-71 freezes it at nine. The response and prompt 16's confirmation copy must say so
plainly — *"removed from Setlistify; the playlist stays in your Spotify account until you delete it
there"*. Silently leaving the impression that a provider-side playlist was removed would be exactly
the kind of dishonesty this product is built against. Deleting the `Playlist` row does **not** delete
the `PlaylistGenerationJob` (the metrics survive); deleting the `Concert` cascades to both.

---

### 7. Backoffice — D-158

Read-only, inside the existing audited, 2FA-gated `/admin` firewall, reading Doctrine directly and
touching no query extension. Both controllers extend `App\Controller\Admin\AbstractAdminCrudController`,
which already makes read-only the structural default (D-46).

**`PlaylistGenerationJobCrudController`**

- **List**: created, user (via `App\Field\MaskedEmailField`, D-51), concert, provider, mode,
  **state**, duration, matched/total, `algorithmVersion`.
- **Filters**: state, provider, mode, `blockedReason`, `failureReason`.
- **Detail**: the full `PlaylistTrack` table with per-song outcome, confidence and reason code; the
  block/failure detail; `stageTimings`; `selectedSetlists` with each band's `selectionReason`.

**`PlaylistCrudController`** — read-only list of generated playlists with `reportSummary`,
`providerPlaylistId`, `insertedThroughOrdinal` and the owning job.

**Dashboard panel "Playlist generation (last 7 days)"**, in the shape D-67's setlist.fm panel already
established, fed by `App\Service\Admin\PlaylistGenerationMetrics` (plain DQL/SQL aggregates over
`playlist_generation_jobs` and `playlist_tracks`, no new storage):

| Line | Query |
|---|---|
| Jobs started / completed / blocked / failed | `COUNT(*) GROUP BY state` over `created_at > now() - 7d` |
| **p50 / p95 generation time** | `percentile_cont(0.5\|0.95) WITHIN GROUP (ORDER BY duration_ms)` over `completed` jobs |
| **Mean match rate** | `avg((matched + lowConfidence) / NULLIF(songsTotal - skipped, 0))` |
| `not_found` rate | `sum(not_found_count) / sum(songs_total - skipped_count)` |
| `blockedReason` breakdown | `COUNT(*) GROUP BY blocked_reason` |
| **Five most frequently unmatched `(artist, title)` pairs** | `playlist_tracks` where `outcome = 'not_found'`, grouped by `source_band_id, source_title`, `ORDER BY count DESC LIMIT 5` |

**Investigate-thresholds, highlighted in the panel** (spec 13 §8, initial figures on the same footing
as spec 12's thresholds): p95 **> 90 s**, 7-day match rate **< 0.75**, `blocked` share **> 10 %**.

**No write actions.** Not even a retry button — retry belongs to the user, whose account the playlist
lives in. If an operator action is ever needed it goes through `App\Service\Admin\AuditLogger` like
every other admin write; this feature adds none, so `AuditLogEntry` gains no new entity kind.

A read-only `TrackResolution` list is **explicitly out of scope** here (spec 12 §"Out of Scope" makes
it optional); the schema does not preclude it.

---

### 8. Test plan

Every item names its kind. **No test in the default suite makes an outbound call** (D-2, D-70, D-85).

#### How the outside world is faked, without weakening the single-door tests

Two doubles, both registered **only in the `test` environment**, and neither of which touches the
static tests that keep the seams honest:

- **`App\Tests\Double\Streaming\TestDoubleProvider`** — an implementation of
  `StreamingProviderInterface` living under `backend/tests/`, tagged `app.streaming_provider`, keyed
  `test-double`. This is the shape `App\Tests\Unit\Service\Streaming\TestDoubleProviderIsDiscoverableTest`
  already proved works (AC-9.5). It is scripted per test: a canned candidate list per `SongQuery`, and
  a queue of behaviours (`throw QuotaExhaustedException at call 12`, `throw RegionRestrictedException
  for track X`, `succeed`). Because it lives in `tests/`, `SpotifySymbolIsolationTest` and
  `PlaylistServiceIsProviderFreeTest` — which scan `backend/src/` — are unaffected, and because it
  goes through the locator, **nothing in the pipeline learns a provider exists**.
- **setlist.fm is faked at the data layer, not the HTTP layer.** Tests persist `Setlist`/`Song` rows
  directly, exactly as `SetlistNormalizer` would have. This is not a shortcut — it is what the
  pipeline actually reads (D-131), and it means `App\Service\Playlist\` never needs a
  `SetlistFmClient` reference, so `SetlistGatewayIsOnlyDoorTest` stays green untouched. The one test
  that must exercise `SetlistGateway` (F-01, budget exhaustion) fakes the **budget**, using the same
  `SetlistFmBudget` seam `App\Tests\Setlist\BudgetExhaustionDegradesHonestlyTest` already uses.

Messenger is run in `sync` mode inside the test container for integration tests
(`when@test: transports: { async_playlist: 'in-memory://' }` plus explicit
`$bus->dispatch()`/handler invocation), so a test asserts the *handler's* behaviour without a worker
process. One functional test asserts the routing configuration itself (T-FUNC-03).

#### Unit tests

| # | Test | Asserts |
|---|---|---|
| T-UNIT-01 | `SongNormalizerTest` | Every worked example in spec 12 §1's table, including `Sæglópur → saeglopur`, `Untitled #1 (Vaka) → untitled 1 vaka`, `Los Días Raros` keeping its article, `Rock 'n' Roll` |
| T-UNIT-02 | `TitleSimilarityTest` | Symmetry, code-point safety on a diacritic pair (**the explicit `levenshtein()` regression guard**), `Dice₃` and `WeightedJaccard` by hand-computed values, stop-token weighting |
| T-UNIT-03 | `ArtistSimilarityTest` | The 1.00/0.90/0.85/0.60/0.00 ladder; `Bruce Springsteen` ⊂ `Bruce Springsteen & The E Street Band` |
| T-UNIT-04 | `MatchConfidenceTest` | Spec 12 §3's four worked examples reproduce their stated scores; renormalization when duration and album type are absent; **the artist gate caps at 0.45** |
| T-UNIT-05 | `MatchProfileTest` | `profiles.yaml` loads; a per-provider override merges over the default; env overrides win |
| T-UNIT-06 | `NonSongClassifierTest` | `isTape` short-circuits; whole-title exact matching only — **`Intro` by The xx and `Jam` by Michael Jackson survive**; the advisory signal never promotes `not_found` to `skipped` |
| T-UNIT-07 | `MedleySegmentationTest` | ` / ` and ` > ` split; a segment shorter than 2 chars aborts the split; segments produce ordered `segmentIndex` values |
| T-UNIT-08 | `CoverAttributionTest` | `coverOfName` becomes the expected artist and the `SongQuery`'s `bandName` (D-113) |
| T-UNIT-09 | `SnippetTest` | A snippet in `info` is never searched and never counted as a miss (D-115) |
| T-UNIT-10 | `SubstantialSetlistSelectorTest` | D-132's arithmetic: median over 10, `max(8, ceil(0.60 × median))`, the 24-month recency limit, `fallback_longest`, `only_one_available` |
| T-UNIT-11 | `PlaylistNamerTest` | Both name patterns, the description including `Setlistify job #<id>` and `(17 of 22 songs matched)` |
| T-UNIT-12 | `JobStateMachineTest` | All twenty legal edges of spec 13 §1 succeed; the three named illegal ones (`completed → *`, `building → matching`, `expired → queued`) raise `\LogicException` |
| T-UNIT-13 | `TrackResolutionStoreTest` | Redis read-through, promotion on a durable hit, the three TTLs, deletion on `NotFoundException` |
| T-UNIT-14 | `GenerationEstimatorTest` | `estimatedSecondsRemaining` from a rolling p50 with no history and with history |

#### Static / architecture tests

| # | Test | Asserts |
|---|---|---|
| T-ARCH-01 | `PlaylistServiceIsProviderFreeTest` | No provider symbol and no provider key literal anywhere under `src/Service/Playlist/` (D-159) |
| T-ARCH-02 | `MatchingServiceIsProviderFreeTest` | The same for `src/Service/Matching/` |
| T-ARCH-03 | `JobStateMachineIsOnlyStateWriterTest` | No class other than `JobStateMachine` assigns `PlaylistGenerationJob::$state` |
| T-ARCH-04 | *(existing, must stay green)* `SpotifySymbolIsolationTest`, `SetlistGatewayIsOnlyDoorTest`, `TestDoubleProviderIsDiscoverableTest`, `NoPublicRolesInOpenApiTest`, `AdminOpenApiTest` | The seams this feature could plausibly break |
| T-ARCH-05 | `NaiveConfidenceIsGoneTest` | `naiveConfidence` appears nowhere in `backend/src/` (D-147) |

#### Integration tests (pipeline, over the test-double adapter)

| # | Test | Asserts | Prompt-14 AC |
|---|---|---|---|
| T-INT-01 | **Happy path** | A 19-song setlist produces `completed`/`complete`, 19 `PlaylistTrack` rows, one `createPlaylist()` call, insert ids in setlist order | AC-1 |
| T-INT-02 | Provider selection | The provider comes from `ProviderRegistry`'s default, restricted to `connected` accounts; no hardcoded key | AC-1 |
| T-INT-03 | Multi-band concert | One playlist, bands in **stage order** (`billingOrder DESC`), per-band `selectionReason`, caps applied from the lowest-billed end with report codes *(P-1, P-2)* | AC-1 |
| T-INT-04 | **Band unknown to setlist.fm** | `completed`/`no_source_material`, **no** `createPlaylist()` call, `NO_SETLIST_FOR_BAND`, HTTP 200 on every read | **AC-2** |
| T-INT-05 | **Band known, no songs recorded** | Same, from the `isEmpty`/`songCount = 0` path | **AC-2** |
| T-INT-06 | **Partial success** | 14 of 19: `completed`/`partial`, 19 rows, 5 carrying `not_found` + `TRACK_NOT_IN_CATALOG`, playlist created and playable | **AC-3** |
| T-INT-07 | **Order with a gap in the middle** | A forced miss at position 7 leaves 6 and 8 adjacent in the provider insert sequence while `sourcePosition` still records the gap; the insert id sequence **equals the matched subsequence** of source order | **AC-4** |
| T-INT-08 | **Idempotent start** | Two concurrent `POST`s produce one row; the second returns 200 with the same id | **AC-6** |
| T-INT-09 | **Idempotent retry after creation** | A retry with `providerPlaylistId` set makes **zero** `createPlaylist()` calls | **AC-6** |
| T-INT-10 | **Idempotent retry after partial insertion** | A retry resumes at the watermark, re-sends no batch, and sets `RESUMED_MID_INSERTION` | **AC-6** |
| T-INT-11 | **setlist.fm budget exhausted mid-run** | `blocked`/`setlistfm_budget` with `resumableAfter = budgetResetAt`; everything computed is kept; `app:playlist:resume-blocked` re-queues it after the instant passes | **AC-7** |
| T-INT-12 | **Provider quota exhausted mid-run** | `QuotaExhaustedException` at song 12 → `blocked`/`provider_quota`; the resumed run re-resolves nothing already in `TrackResolution` and starts at song 12 | **AC-7** |
| T-INT-13 | **Provider disabled mid-run** | `ProviderSetting.enabled` flipped between stages → `blocked`/`provider_disabled`, typed, never a 500 | **AC-8** |
| T-INT-14 | **Token expiry mid-run** | `TokenExpiredException` → `blocked`/`needs_reauth`, `resumableAfter = null`; the account is already `needs_reauth`; re-linking re-queues without restarting | AC-7, AC-10 |
| T-INT-15 | Rate limit | Three inline retries honouring `retryAfterSeconds` (capped at 30 s), then `blocked`/`provider_rate_limit` | — |
| T-INT-16 | Region restriction | `RegionRestrictedException` at insert → per-track `region_restricted`; **the `TrackResolution` row survives** | AC-3 |
| T-INT-17 | Vanished track (F-13) | `NotFoundException` at insert → per-track `not_found`; **the `TrackResolution` row is deleted** | AC-3 |
| T-INT-18 | Zero tracks matched | T-09: `completed`/`no_tracks_matched`, **no** provider playlist created | AC-2 |
| T-INT-19 | F-14 indeterminate creation | Marker set, id null → `failed`/`creation_indeterminate`, **no** second create; `create-anyway` clears and re-queues *(P-3)* | AC-6 |
| T-INT-20 | Block cycle exhaustion | A fourth block cycle → `failed` (T-14) | AC-7 |
| T-INT-21 | Progress observability | `songsProcessed` advances per song and survives a simulated worker kill mid-match | **AC-10** |
| T-INT-22 | **One pipeline, two modes** (spec 13's AC-4.2, pre-built here) | A `normal`-mode job on a band with one usable setlist and an empty CHOICE band produces a state sequence and `PlaylistTrack` set identical to the `fast` job for the same concert | AC for prompt 17 |

#### Functional tests (HTTP)

| # | Test | Asserts |
|---|---|---|
| T-FUNC-01 | `PlaylistGenerationApiTest` | `POST` returns 201 in-request with zero provider and zero setlist.fm calls; the second `POST` returns 200 with the same job (AC-10) |
| T-FUNC-02 | `PlaylistPollingApiTest` | `Retry-After` per state, `ETag` + 304, and **200 (never an error status) for a `blocked` job** |
| T-FUNC-03 | `PlaylistGenerationIsAsyncTest` | `BuildPlaylistMessage` is routed to `async_playlist`, not `sync://` — the configuration itself, so a future `messenger.yaml` edit cannot silently make generation synchronous |
| T-FUNC-04 | `PlaylistOwnershipTest` | Another owner's job id and playlist id both return **404**, byte-identical to a missing id; `ConcertOwnerExtension` is unmodified |
| T-FUNC-05 | `PlaylistDeleteTest` | 204, local rows gone, the job row retained, no provider call made (D-151) |
| T-FUNC-06 | `PlaylistBackofficeTest` | Both admin lists render across owners, the dashboard panel computes its six lines, and **no write action exists** on either controller |
| T-FUNC-07 | `PlaylistOpenApiTest` | The four capabilities appear in the generated document; **no `/admin` route does** |
| T-FUNC-08 | `ExpireAndResumeCommandsTest` | `app:playlist:expire-jobs` and `app:playlist:resume-blocked` are idempotent, lock-guarded, and bounded by their indexes |

#### The match-quality regression gate

| # | Test | Asserts |
|---|---|---|
| T-QUAL-01 | `MatchingQualityHarnessTest`, `@group matching-quality`, in the **default** suite | Runs the full cascade over spec 12 §9's eight hand-labelled fixture setlists (~180–220 entries) against **committed** provider search responses, computes the four metrics, and **fails the build** if auto-accept precision < **0.95**, non-song precision < **1.00**, or the silent-error rate > **0.03**. Writes `var/matching-report.json` with per-song outcomes so a diff between runs shows *which* songs changed |

Guarding this test is the point (prompt 14's own risk note calls it *"the only thing standing between
a matching tweak and a silent regression across every future generation"*). Three properties make it
a gate rather than a formality:

1. **It runs in `composer test`**, not behind an opt-in group filter. `@group matching-quality` exists
   to let it be run *alone*, never to let it be skipped.
2. **The fixture set is frozen across a change** (D-122): a pull request may not add fixtures and
   change the algorithm at once. Fixtures land first, with current numbers recorded; the change lands
   second. A `FixtureManifestIsFrozenTest` asserts the manifest's checksum matches the numbers
   recorded alongside it, so the freeze rule is mechanical rather than remembered.
3. **The thresholds in spec 12 §3 are guesses until this runs.** D-122 makes it prompt 14's explicit
   obligation to run the harness and **write the tuned values back into spec 12 in this branch**. If
   the tuned numbers move, `matching.algorithmVersion` moves with them.

The two non-code dependencies this inherits are real and are listed in §Dependencies: capturing the
provider search responses once, by hand, and hand-labelling ~200 entries (one human, one pass).

---

### 9. Messenger wiring — D-145

**Nothing async exists today.** `backend/config/packages/messenger.yaml` routes everything to
`sync://` with the `async` and `failed` transports commented out; `backend/src/Message/README.md` and
`backend/src/MessageHandler/README.md` each say "out of scope, nothing is wired";
`MESSENGER_TRANSPORT_DSN` sits in `.env.example` unused. Wiring it is part of this feature, and it is
not optional: without a running worker every job sits in `queued` forever (spec 13's R-8).

#### Transport and routing

```yaml
# backend/config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            async_playlist:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'      # redis://redis:6379/messages
                options:
                    stream: playlist
                    group: setlistify
                    consumer: '%env(default::HOSTNAME)%'
                retry_strategy:
                    max_retries: 3
                    delay: 5000            # 5 s → 30 s → 180 s
                    multiplier: 6
                    max_delay: 180000
                    jitter: 0.2
            failed: 'doctrine://default?queue_name=failed'

        routing:
            'App\Message\BuildPlaylistMessage': async_playlist

when@test:
    framework:
        messenger:
            transports:
                async_playlist: 'in-memory://'
```

- **Redis, not Doctrine, for the working transport.** The DSN is already in `.env.example`, Redis is
  already a compose service, and the workload is a low-volume stream of short-lived jobs. Spec 13's
  own assumption stands: if durability across a Redis restart ever outweighs throughput, the Doctrine
  transport is a one-line DSN change and nothing else moves.
- **Doctrine for the failure transport**, so a poisoned message is inspectable with
  `messenger:failed:show` rather than lost.
- **The retry policy is spec 13's** — 3 attempts, 5 s / 30 s / 180 s, 20 % jitter (D-144). Messenger
  retries cover transport-level failures (F-12); *domain* waiting is `blocked`, not a retry, which is
  why `GenerationBlockedException` is caught and acknowledged rather than rethrown.
- `attempt` on the job row is **not** Messenger's redelivery count. It is incremented only by T-16
  (a user retry). Conflating the two would make the idempotency key move under a redelivery.

#### The worker

```yaml
# compose.yaml — new service
  worker:
    build: { context: ./backend, dockerfile: ../docker/backend/Dockerfile }
    restart: unless-stopped
    depends_on:
      postgres: { condition: service_healthy }
      redis:    { condition: service_healthy }
    env_file: [ ./backend/.env.local ]
    volumes: [ ./backend:/app ]
    command: >
      bin/console messenger:consume async_playlist
        --time-limit=3600 --memory-limit=256M --limit=0 --failure-limit=10 -v
    deploy:
      replicas: ${PLAYLIST_WORKER_COUNT:-2}
```

`--time-limit=3600` and `--memory-limit=256M` make the worker recycle itself rather than accumulate
leaked state; `restart: unless-stopped` is the supervisor. **`PLAYLIST_WORKER_COUNT = 2`** to start
(D-144): enough that one slow job does not stall the queue, small enough that two users cannot
jointly exhaust a provider's per-second rate limit. Production supervision is the platform's process
manager running the same command.

#### The two cron entries

Neither is scheduled from inside the app — there is no `symfony/scheduler` dependency, and
`app:setlist:refresh` already established the deployment-cron pattern (D-65):

| Command | Cadence | Does |
|---|---|---|
| `app:playlist:resume-blocked` | **every 5 minutes** | `SELECT … WHERE state = 'blocked' AND resumable_after <= now()` (indexed), re-tests each job's block reason, and on a clear re-test dispatches a fresh `BuildPlaylistMessage` with `blockCycleCount++` (T-13). Past `MAX_BLOCK_CYCLES = 3` → `failed` (T-14). Lock-guarded so two overlapping runs cannot double-dispatch |
| `app:playlist:expire-jobs` | **nightly** | Moves suspended jobs past their TTL to `expired` (T-17), **keeps `userChoices`**, **drops `candidateSetlists`/`pendingChoices`**, and prunes `track_resolutions` rows more than one `algorithmVersion` behind. Bounded by `idx_pgj_state_expires` |

Both are idempotent and safe to run concurrently, matching `app:setlist:refresh`'s documented posture.

#### Configuration that ships with it

New entries in **both** `docs/env-vars.md` and `backend/.env.example` (both files or neither):

| Variable | Secret | Default | Purpose |
|---|---|---|---|
| `MESSENGER_TRANSPORT_DSN` | yes | `redis://redis:6379/messages` | **Promoted from unused to used** |
| `PLAYLIST_WORKER_COUNT` | no | `2` | Worker replicas (D-144) |
| `GENERATION_MAX_BANDS` | no | `4` | *(P-1, accepted)* |
| `GENERATION_MAX_SONGS` | no | `60` | *(P-1, accepted)* |
| `GENERATION_SETLIST_PAGES` | no | `1` | setlist.fm index pages per band per generation (D-131) |
| `GENERATION_SUBSTANTIAL_RATIO` | no | `0.60` | D-132. A guess until there is data |
| `GENERATION_SUBSTANTIAL_FLOOR` | no | `8` | D-132 |
| `GENERATION_SELECTION_WINDOW` | no | `20` | D-132 |
| `GENERATION_RECENCY_LIMIT_MONTHS` | no | `24` | D-132 |
| `GENERATION_INSERT_BATCH_SIZE` | no | `50` | `min(50, provider maximum)` (D-137) |
| `GENERATION_MAX_BLOCK_CYCLES` | no | `3` | T-14 |
| `GENERATION_RATE_LIMIT_INLINE_RETRIES` | no | `3` | F-05 |
| `SUSPENDED_JOB_TTL_SETLIST_CHOICE` | no | `604800` | 7 days *(P-4, accepted)* |
| `SUSPENDED_JOB_TTL_VERSION_CHOICE` | no | `259200` | 72 hours *(P-4, accepted)* |
| `MATCHING_AUTO_ACCEPT_THRESHOLD` | no | *(unset)* | **Operational escape hatch only**, not a tuning mechanism (D-110) |
| `MATCHING_CHOICE_THRESHOLD` | no | *(unset)* | Same |

---

## Decisions

Numbered from **D-145**, continuing the project-wide sequence. `D-1`–`D-3` project-wide, `D-4`–`D-9`
backend skeleton, `D-10`–`D-17` frontend skeleton, `D-18`–`D-23` auth, `D-24`–`D-31` concert domain,
`D-32`–`D-41` concert tracker UI, `D-42`–`D-55` backoffice foundation, `D-56`–`D-70` setlist.fm,
`D-71`–`D-88` streaming port, `D-89`–`D-105` provider configuration, `D-106`–`D-124` song matching
(spec 12), `D-125`–`D-144` playlist pipeline (spec 13).

**D-145 — Messenger is wired in this feature: one Redis transport `async_playlist`, a Doctrine
failure transport, a supervised compose worker, and two deployment cron entries.**
Nothing async exists today — `messenger.yaml` routes everything to `sync://` and both message
directories hold a README saying so. Redis is chosen for the working transport because the DSN and the
service already exist and the workload is low-volume and short-lived; Doctrine is chosen for the
failure transport because an inspectable poison message is worth a table. The retry policy is spec
13's (3 attempts, 5 s / 30 s / 180 s, 20 % jitter) and covers transport failures only — domain waiting
is `blocked`, so `GenerationBlockedException` is acknowledged rather than retried. The worker is not
optional infrastructure: without it every job sits in `queued`, which is why it ships with a compose
service, a README operations entry and a deployment-doc entry in the same branch.

**D-146 — The four new tables use integer surrogate identity, not the UUIDs the spikes sketched.**
Every existing entity uses `#[ORM\GeneratedValue]` integers; introducing a second identity convention
for four tables would fork the locator shape, the URL patterns and the dependency question for no
behavioural gain. Enumerability is already the accepted posture for `Concert` behind
`ConcertOwnerExtension`'s cross-owner 404, which these resources copy exactly. The one thing spec 13
leaned on a UUID for — F-14's orphan marker — is served by `Setlistify job #<id>`. Written back into
spec 12 §8 and spec 13 §2 in the same branch, as prompt 14's brief requires.

**D-147 — `SpotifyTrackMapper::naiveConfidence()` is deleted outright; the adapter keeps only signal
extraction.**
D-83 promised prompt 12 would replace it, and spec 12 §2 gives the concrete reason: `levenshtein()` is
byte-based, so an accented title is systematically scored below its unaccented twin, and the
denominator is in bytes too. Deprecating it would leave two scorers and let a future call site pick
the wrong one. The adapter instead gains the job of populating `TrackCandidate`'s generic signal
fields (D-119) and a `SpotifyQueryBuilder` so query construction has one home. `TrackCandidate`'s new
fields do not reopen D-71: the port keeps nine methods, and the fields are generic concepts any
provider can answer or leave null. A static test asserts `naiveConfidence` appears nowhere in `src/`.

**D-148 — `algorithmVersion` is configuration, copied onto every job row and part of the resolution
cache key; bumping it is part of the definition of a matching change.**
It is what lets two calibrations coexist without mixing (spec 12 §8), what makes a before/after
comparison a `GROUP BY` rather than an archaeology exercise, and what a config file reviewed in a pull
request can enforce where a backoffice field could not (D-110). Old-version resolution rows are kept
so the harness can diff two calibrations over one corpus; `app:playlist:expire-jobs` prunes anything
more than one version behind.

**D-149 — The API surface is four capabilities across two resources, following the project's
DTO-plus-state-provider shape, with no entity binding.**
`PlaylistGenerationJobResource` and `PlaylistResource` are `#[ApiResource]` classes with dedicated
`Output` DTOs, one provider per read and one processor per write — the shape D-22 established and D-29
confirmed. The alternative, binding the entities directly, would put `providerPlaylistId`,
`candidatesDigest` and `idempotencyKey` one serialization group away from the public contract. Seven
operations: start, poll, list, retry, cancel, create-anyway, fetch, list-playlists, delete. **The
generated OpenAPI document is the source of truth; no endpoint is listed in any README.**

**D-150 — Polling is a plain `GET` with `ETag`/304 and a state-derived `Retry-After`, and a `blocked`
job is always HTTP 200.**
Spec 13's D-128 chose polling and wrote the Expo reasoning down; this decision is only about the HTTP
mechanics. `Retry-After` is 1 during `matching`/`building`, 3 during `queued`/`resolving_setlist` and
**absent** on every terminal, blocked and suspended state — so a client stops polling by the header's
absence rather than by enumerating eleven state names in two codebases. The `ETag` needs a cheap
change token, which is why `updated_at` is added to the job row (an addition over spec 13's sketch,
written back). Emitting an error status for `blocked` would undo the entire `blocked`-is-not-a-failure
design at the transport layer, which is R-2 of spec 13 realized as a bug.

**D-151 — Deleting a playlist deletes our rows only, and says so.**
The port has no delete method and D-71 freezes it at nine, so the provider-side playlist survives. The
alternatives were a tenth port method (rejected for one caller and a freeze worth more) and silence
(rejected outright — letting a user believe their Spotify playlist was removed is precisely the
dishonesty this product is built against). The 204 is accompanied by copy prompt 16 must render:
*"removed from Setlistify; the playlist stays in your provider account until you delete it there."*
The `PlaylistGenerationJob` row survives a playlist deletion so the metrics do; deleting the `Concert`
cascades to both.

**D-152 — External systems are faked at two different layers, chosen so no single-door test weakens.**
The provider is faked with a `test`-environment adapter registered through the existing tagged-service
locator — the shape `TestDoubleProviderIsDiscoverableTest` already proved — so nothing upstream learns
a provider exists and the symbol-isolation scan of `src/` is untouched. setlist.fm is faked **at the
data layer**, by persisting `Setlist`/`Song` rows, because that is genuinely what the pipeline reads
(D-131); faking it at the HTTP layer would require `App\Service\Playlist\` to know a client exists and
would put `SetlistGatewayIsOnlyDoorTest` under pressure. The one budget-exhaustion test uses the
`SetlistFmBudget` seam an existing test already exercises.

**D-153 — The fixture harness runs in the default suite and fails the build; the fixture set is frozen
across a change, enforced by a manifest checksum.**
Prompt 14's own risk note makes this the only guard against a silent quality regression, and a gate
that can be skipped is not a gate. Auto-accept precision (≥ 0.95 Spotify) and non-song precision
(= 1.00) are hard, and the silent-error rate (≤ 0.03) is hard — the three that represent *silent*
failures. Coverage is a target that may be traded down to protect precision, never the reverse
(D-123). The freeze rule is mechanical rather than remembered: a manifest checksum test fails if
fixtures and algorithm move in one pull request.

**D-154 — The `CHOICE` band is included and flagged in Fast mode, and the flag is a distinct stored
outcome.**
Spec 12's resolved question 1 settled the behaviour; this decision is that it is carried by a distinct
`outcome` value (`matched_low_confidence`) rather than by a confidence threshold re-derived at read
time. A stored outcome means the report, the counters, the backoffice panel and prompt 16 all read one
field, and a later threshold change does not retroactively re-label finished playlists.

**D-155 — Setlist selection and matching never spend setlist.fm budget speculatively, and the
selection reason is always rendered.**
Cached rows first; at most one index page per band per generation; never a per-setlist detail fetch;
never a "check for anything newer" read (D-65's rule applied to a job). If the budget is spent and
*some* cache exists, generation proceeds on cached data with `SETLIST_MAY_BE_STALE`; if nothing is
cached, F-01 blocks with `budgetResetAt`. Because D-132's rule is opinionated enough to pick a
different night than the user expected, `selectionReason` is persisted per band and rendered on every
playlist — a default this strong must be visible.

**D-156 — Every generation constant is a container parameter with an env override, and the four
pending items are named as such.**
`GENERATION_MAX_BANDS`, `GENERATION_MAX_SONGS`, `GENERATION_SUBSTANTIAL_RATIO`, the two
`SUSPENDED_JOB_TTL_*` values and the rest are parameters, not literals, precisely because spec 13
expects several of them to be revised against real data (its R-4, R-7 and R-10). The four items spec
13 left open (P-1…P-4) are implemented at spec 13's recommended values and marked *pending user
confirmation* wherever they appear, so a rejection at review changes a default and nothing else.

**D-157 — `Playlist` and `PlaylistGenerationJob` copy `ConcertOwnerExtension`'s cross-owner 404 shape
exactly, and `ConcertOwnerExtension` itself is not touched.**
Two new query extensions and two new voters, each a near-copy: the extension filters every read —
collection and item — to the current owner *before* a voter runs, so a cross-owner request finds
nothing and gets the framework's ordinary 404. A 403 would confirm the id exists. The temptation this
decision forecloses is making the existing extension role-aware so the backoffice can reuse it: D-47
says the admin is a separate channel, and adding a `ROLE_ADMIN` branch to the class that guards every
user's data on the public API would put an admin-only conditional on the hottest security path in the
codebase.

**D-158 — The backoffice additions are read-only, and the metrics panel ships in this branch.**
Prompt 14's risk note says generation time and match quality are the two numbers that matter and must
be measured from the first day; a number nobody stored is a number nobody will have. Both are columns
(D-141) and both are aggregated by a panel in the shape D-67's setlist.fm panel established. No write
action of any kind — not even a retry button, because retry belongs to the user whose account the
playlist lives in. Admin reads go through Doctrine directly, never through a query extension (D-47).

**D-159 — Two structural rules get static tests: `App\Service\Playlist\` is provider-free, and
`JobStateMachine` is the only writer of `$state`.**
Both are the same technique `SetlistGatewayIsOnlyDoorTest` and `SpotifySymbolIsolationTest` already
use, for the same reason: a rule enforced by review is a rule that survives until the reviewer is
busy. Eleven states scattered across seven stage classes is exactly how a state machine becomes untrue
to its own diagram, and a provider key literal in a stage class is exactly how prompt 18 becomes a
rewrite.

**D-160 — Prompt 14 writes its measured numbers back into the two spikes in this branch.**
Three concrete instances, all required before the PR is mergeable: (a) spec 12's thresholds, replaced
by the harness's tuned values (D-122); (b) spec 12 §8 and spec 13 §2's identity sketches, corrected
per D-146; (c) spec 13's `updated_at` addition (D-150). Anything else implementation proves wrong is
corrected the same way. A spike that quietly stops describing the code is worse than no spike, because
the next prompt will trust it.

---

## Out of Scope

| Not in this feature | Where it belongs |
|---|---|
| **Any UI** — the generation trigger, the progress screen, the four result variants, the report screen, every degraded state | **Prompt 16.** This feature ships the API and the report *codes*; not a sentence of user-facing English is final here |
| **Interactive selection** — choosing which setlist, choosing which version, the suspend/resume flow, the four Normal-mode endpoints | **Prompt 17.** The two suspension states, the two TTLs, the two JSONB payload columns, the expiry sweeper and the staleness rules ship here as *unused capacity* so prompt 17 adds two guards rather than a second pipeline |
| **The second provider** — the YouTube adapter, Google OAuth, unit accounting, YouTube calibration | **Prompt 18.** F-04 is provider-agnostic by construction: `App\Service\Playlist\` never learns what a unit is |
| Multi-provider generation (one concert, two providers at once) | Prompt 18 makes it possible; `uniq_live_generation` already permits one job per `(concert, provider)`, so two providers is two jobs and needs no new design |
| Playback of the generated playlist | Prompt 19. `playlistEmbedUrl()`/`playlistDeepLink()` already exist; `Playlist.providerPlaylistId` is what prompt 19 reads |
| Editing a playlist after creation — reorder, add, remove, rename | Would need port methods D-71 freezes. Out of prompt 17's scope too |
| Sharing the playlist | Prompt 21 |
| Per-user generation limits and entitlements | Prompt 22. `uniq_live_generation_per_user` is an anti-starvation floor, not a product limit |
| Push notification on completion | Noted in prompt 16's risks. A feature with its own permission flow, not a progress mechanism (D-128) |
| A backoffice screen for `TrackResolution`, and an operator "forget this resolution" action | Spec 12 §8 explicitly makes it optional; the schema does not preclude it |
| Richer metadata (MusicBrainz canonical titles, durations) | Prompt 24. The duration signal is defined, weighted and normally absent by design (D-109) |
| A general workflow/step engine | Spec 13's R-1. Eleven states, one handler, two `if`s. Anything more is speculative |

---

## Dependencies

**Must be true before implementation starts**

| Dependency | Provides | Status |
|---|---|---|
| **Prompt 09 merged** | `Setlist`/`Song` rows, `SetlistGateway`, `CachedFetch::$budgetResetAt`, `BandIdentityResolver`, `SetlistNormalizer::hydrateSetlistsPage()` | **Met** |
| **Prompt 10 merged** | The nine frozen port methods, `SongQuery`/`TrackCandidate`/`PlaylistDraft`/`ProviderPlaylist`/`ProviderTokens`, `StreamingProviderLocator`, `StreamingTokenManager::usableTokens()`, six typed exceptions | **Met** |
| **Prompt 11 merged** | `ProviderRegistry::isAvailable()`, `ProviderDisabledException` | **Met** |
| **Spec 12 approved** | The whole matching design, D-106–D-124, nothing deferred | **Met — approved 2026-08-23** |
| **Spec 13 approved** | The whole pipeline design, D-125–D-144 | **PENDING — review requested.** This spec cannot be implemented until spec 13 is approved, and its four open questions (P-1…P-4) resolved |
| `BandResolver::normalize()` as a service | The artist side of every comparison (D-106) | **Met** |
| Redis + PostgreSQL from `compose.yaml` | Transport, locks, both cache tiers, durable job state | **Met** |
| A linked, `connected` `StreamingAccount` for the generating user | Every provider call | **Met** (prompt 10) |
| A Spotify developer application with the developer's account allowlisted | Fixture capture, under the 5-user Development Mode cap | **Met** (prompt 10) |
| **Recorded provider search fixtures** for the eight fixture setlists | §8's harness, which must make zero outbound calls | **To capture — a one-time manual session in this branch.** Blocking for T-QUAL-01 only |
| **Hand-labelled expected outcomes** for ~200 song entries | The harness's ground truth | **To do — one human, one pass.** The single largest non-code task this feature inherits (spec 12's R-5) |
| A **worker process** in every environment | Otherwise every job sits in `queued` | **To build here** — compose service, README operations entry, deployment doc |
| **Two cron entries** in every environment | `app:playlist:resume-blocked` (5 min), `app:playlist:expire-jobs` (nightly) | **To build here** |

**Depended on by**

- **Prompt 16 (fast mode UI)** — consumes §6's operations, the polling contract, the `blockedReason`
  vocabulary and the report codes.
- **Prompt 17 (normal mode)** — consumes the two suspension states, the two JSONB columns, the two
  TTLs, the expiry sweeper and T-INT-22's one-pipeline property.
- **Prompt 18 (YouTube adapter)** — consumes `profiles.yaml`'s per-provider shape, F-04's contract
  (raise `QuotaExhaustedException` inside the adapter and the pipeline handles it with no
  YouTube-specific code upstream) and `TrackCandidate`'s signal fields.
- **Prompt 19 (playback)** — reads `Playlist.providerPlaylistId`.
- **Prompt 22 (entitlement and quota)** — replaces the anti-starvation index with real per-user
  limits, at `PreflightStage`.

**Assumptions** *(labelled as assumptions, not verified facts)*

- Every assumption carried by specs 12 and 13 carries forward unchanged — in particular setlist.fm's
  medley convention (` / `, occasionally ` > `), the absence of a song duration upstream, the absence
  of a batch search form on either provider, Spotify's 100-id add limit, and D-132's festival/support
  set-length figures.
- `SetlistNormalizer::hydrateSetlistsPage()` hydrates full `Song` rows from the index payload. Read
  from the shared `hydrateOne()` call path, but stated as an assumption about **setlist.fm's index
  response**. If the index ever returns song-less summaries, D-131's "zero extra calls" becomes "one
  detail fetch per chosen setlist per band", and only that changes.
- Symfony Messenger's Redis transport is sufficient for this workload; a Doctrine transport is a
  one-line DSN change if durability ever outweighs throughput.
- A 25-song warm-cache generation takes ~30 s, so §6's ~20 polls per generation is the right order of
  magnitude. The conclusion survives being wrong by 3×.
- `TrackCandidate` is constructed positionally only inside adapters, so appending defaulted fields
  breaks no call site outside `Service/Streaming/`. To be verified with `codegraph_callers` before the
  change lands.

---

## Risks and Open Questions

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **The fixture harness is skipped or weakened under schedule pressure**, because hand-labelling ~200 entries is unglamorous work | **High and quiet** — without ground truth, every later matching change is opinion | D-153 puts it in the default suite with hard thresholds and a frozen-manifest test. The labelling is a named, sized, blocking dependency, not a follow-up |
| R-2 | **`blocked` is rendered as an error** somewhere between the API and the UI, undoing the design | **High** — it turns "we'll finish this at midnight" into "something went wrong" | D-150 makes a `blocked` job an HTTP 200 with no `Retry-After`, T-FUNC-02 asserts it, and prompt 16's brief already forbids a red error colour on partial results |
| R-3 | **The worker is not running** in some environment and every job sits in `queued` | **High and embarrassing** | Three deliverables in this branch (compose service, README operations entry, deployment doc), plus the dashboard's "started vs completed" line, which makes it obvious within one panel view |
| R-4 | **The first migration is large** — four tables, two partial unique indexes, several enum columns — and a mistake in it is expensive to unwind | Medium | Generated by `doctrine:migrations:diff`, hand-edited only for the partial indexes and `ON DELETE` behaviours, with a working `down()`. Enum columns are `VARCHAR` + `enumType:` so adding a state is never a migration |
| R-5 | **Matching thresholds tune worse than hoped**, and the instinct is to lower the auto-accept bar to hit a coverage number | Medium | D-123's trade direction is stated in advance: coverage may be traded down to protect precision, never the reverse. D-117 forbids relaxing the threshold for songs we most want to find |
| R-6 | **The pipeline grows into a workflow engine** — eleven states and two sweepers is exactly the shape that invites a generic step registry | High, and enjoyable to do | Spec 13's R-1 is the acceptance criterion. One entity, one handler, two mode `if`s, no DSL. `JobStateMachine` is a table of legal edges and nothing more, enforced by T-ARCH-03 |
| R-7 | **A provider key literal leaks into `Service/Playlist/`** during implementation, making prompt 18 a rewrite | High | T-ARCH-01/T-ARCH-02, plus the existing `SpotifySymbolIsolationTest`. Per-provider configuration is keyed by `key()`, a runtime string |
| R-8 | **The multi-band caps bite real festivals** — 4 bands and 60 songs may be too few for the concerts people actually track | Medium | *(P-1)* Both are parameters with a report code on every cut, so the cases where they bite are **counted** in the backoffice rather than guessed at. Prompt 18's quota increase is what would relax them |
| R-9 | **F-14 fires and confuses a user** the first time | Low frequency, high confusion | *(P-3)* Deliberately chosen over a silent duplicate. The deterministic playlist name and the `Setlistify job #<id>` description marker make the user's side actionable, and `create-anyway` is one tap |
| R-10 | **`TrackResolution` makes a resumed run cheap — until `algorithmVersion` moves**, at which point a resumed job re-spends its whole budget | Low-medium | D-121 keeps old-version rows, so the miss is by design rather than by accident; worth measuring once there is traffic. The job's stored `algorithmVersion` is what makes the measurement possible |
| R-11 | **Per-song progress writes contend** with the run's own transactions | Low | Progress is its own short transaction on its own row, taken *between* provider calls, never inside one. Twenty-five short `UPDATE`s over 30 seconds |
| R-12 | **This implementation diverges from the spikes silently** because reality disagrees | Medium | D-160 makes writing the divergence back a merge requirement. The three known instances are already named |

**Resolved on approval — 2026-08-23**

These are **spec 13's** four questions. This spec was written against their recommendations, and on
approval **every recommendation was accepted, unchanged**.

1. **`GENERATION_MAX_BANDS = 4` and `GENERATION_MAX_SONGS = 60`** (P-1) — **accepted**. Quota-driven,
   not product-driven; a genuine festival is the case they cut, revisited when prompt 18 has a YouTube
   quota increase.
2. **Stage order rather than billing order** for multi-band playlists (P-2) — **accepted**.
   `ORDER BY billingOrder DESC`, support acts first, headliner last — the *opposite* of how the API
   lists a lineup, but the order the night happened in.
3. **F-14's honest gap** (P-3) — **accepted**. Surface a possible orphan playlist to the user via
   `failed`/`creation_indeterminate` plus `create-anyway`, rather than silently creating a second one
   or adding a tenth method to the frozen port.
4. **`SUSPENDED_JOB_TTL` of 7 days / 72 hours** (P-4) — **accepted**, as 604800 / 259200 seconds. Fast
   mode never suspends, so this ships as columns and a sweeper with no runtime effect in this feature.

One further obligation, not a question: **spec 12's thresholds are guesses until §8's harness runs.**
D-122 and D-160 make running it and writing the tuned values back into spec 12 part of this branch,
not a follow-up ticket.

---

## Documentation to update *(in this branch, before the PR)*

Per `CLAUDE.md`'s mandatory pre-commit checklist — run `/doc-check` against the diff.

- [ ] **`docs/architecture.md`** — record **D-145**–**D-160**; rewrite **§8** with the real state
      machine, the stage list and the failure taxonomy; fill in **§10**'s `Playlist`/`PlaylistTrack`
      sketch and add `PlaylistGenerationJob` and `TrackResolution`; extend **§9** with the two
      generation views and the dashboard panel; note in **§3** that `Service/Matching/` and
      `Service/Playlist/` are now real.
- [ ] **`docs/env-vars.md`** *and* **`backend/.env.example`** — every variable in §9's table. **Both
      files or neither.** The `MATCHING_*` entries must say *operational escape hatch, not a tuning
      mechanism* in those words (D-110).
- [ ] **Root `README.md`** — the `worker` service under **Services**, and the two cron entries under
      **Operations**, alongside `app:setlist:refresh`.
- [ ] **`compose.yaml`** — the `messenger:consume async_playlist` worker service.
- [ ] **`backend/README.md`** — nothing about endpoints (the OpenAPI document is the source of truth);
      add the `@group matching-quality` and `--group live` distinction if the fixture harness needs a
      standalone invocation, and the migration note if `doctrine:migrations:diff` output needed
      hand-editing.
- [ ] **`backend/src/Message/README.md`** and **`backend/src/MessageHandler/README.md`** — both
      currently say "out of scope, nothing is wired". This is the branch that makes them untrue.
- [ ] **New `backend/src/Service/Playlist/README.md`** — the provider-free rule and the
      `JobStateMachine`-is-the-only-writer rule, in the spirit of the existing service READMEs.
- [ ] **New `backend/src/Service/Matching/README.md`** — the provider-free rule for that directory.
- [ ] **`backend/src/Service/Streaming/README.md`** — confidence scoring has left the adapter (D-83
      redeemed, D-147); the adapter retains query construction, response mapping and signal
      extraction.
- [ ] **`docs/external-apis.md`** — the generation-shaped consumption pattern (searches **plus**
      inserts per generation) under §YouTube with spec 12 §7's arithmetic, the audio-features
      restriction finding (D-124) under §Spotify, and a change-log entry.
- [ ] **Deployment docs** — the worker process and the two cron entries as deployment requirements.
- [ ] **The OpenAPI document** — regenerated from the new API Platform resources. **No endpoint is
      listed in any README**, and no `/admin` route appears in it (`AdminOpenApiTest` enforces this).
- [ ] **`docs/specs/2026-08-22-spike-song-matching.md`** — the tuned thresholds and the harness's first
      numbers (D-122), and the identity correction (D-146).
- [ ] **`docs/specs/2026-08-23-spike-playlist-pipeline.md`** — the identity correction (D-146) and the
      `updated_at` addition (D-150).

---

## Recommendation Summary

**Follow the two spikes exactly, and spend this feature's own judgement only on the seams they did not
touch.**

Almost every hard question this feature raises has already been answered: which track a song becomes,
what confidence means, what happens to a medley, which setlist gets picked, what "blocked" means, how
a retry avoids duplicating, what the report stores. Specs 12 and 13 argued all of it and left nothing
as TBD. The single largest risk in implementing them is not that they are wrong — it is that
implementation quietly re-decides one of them in passing, and the record stops describing the code.
D-160 exists for that reason and is a merge requirement, not a courtesy.

What this document adds is deliberately small and mostly structural:

- **Identity and the migration** (D-146) — one convention, four tables, two partial unique indexes
  that are constraints rather than conventions.
- **Messenger, from nothing** (D-145) — a transport, a failure transport, a supervised worker and two
  cron entries. Currently `sync://` and two READMEs saying so; without this the whole feature is a row
  that never moves.
- **The API's honesty at the transport layer** (D-150, D-151) — a `blocked` job is a 200, a poll that
  found nothing is a 304, and deleting a playlist says what it did and did not delete.
- **Two static tests and a build-failing quality gate** (D-153, D-159) — because the provider-free
  rule, the single-state-writer rule and the match-quality number are all things that survive exactly
  as long as someone remembers them, unless a test remembers instead.
- **Read-only measurement from day one** (D-158) — the two numbers prompt 14's brief calls decisive,
  as columns and a panel, in this branch.

The four things this document does not decide itself are spec 13's four open questions. They are
implemented at spec 13's recommended values, and every one of them was accepted, unchanged, on
approval.

---

**Approved — 2026-08-23.** This feature carries decisions **D-145**–**D-160**, all now settled: spec
13 is approved, and its four open questions — the 4-band/60-song caps, stage order, F-14's honest gap
and the 72-hour version-choice TTL — were confirmed exactly as recommended. The four most
consequential decisions here, and the four most worth disagreeing with, are **D-145** (Messenger
wired with a Redis transport and a supervised worker, since nothing async exists today), **D-146**
(integer identity rather than the spikes' UUID sketches), **D-151** (deleting a playlist leaves the
provider-side playlist alone, and says so) and **D-153** (the fixture harness as a build-failing gate
in the default suite, with a frozen fixture manifest).
