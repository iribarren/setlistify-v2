# setlist.fm Integration and the Setlist Cache

| | |
|---|---|
| **Spec ID** | `2026-08-22-setlistfm-integration` |
| **Backlog prompt** | `docs/prompts/09-setlistfm-integration.md` |
| **Command** | `/feature setlistfm-integration` |
| **Primary agent** | `backend-engineer` (one branch, one PR) |
| **Branch** | `feature/setlistfm-integration` |
| **Depends on** | `05` — concert domain API (merged) · `08` — backoffice foundation (merged) |
| **Decisions** | **D-56** – **D-70**, plus **D-254** and **D-255** (the 2026-08-27 amendment below) |
| **Amended by** | `docs/specs/2026-08-27-instant-setlist-refresh.md` (2026-08-27) — narrows **D-65** and **D-67** |
| **Status** | **Approved** (amended 2026-08-27) |

---

## Overview

Everything Setlistify promises starts here. A user records a concert, and the product's whole reason
to exist is that it can then say *what the band actually played*. That data comes from exactly one
place — setlist.fm — and there is no second supplier (`docs/external-apis.md`). This feature builds
the road to it.

It looks disproportionately heavy for "call an API and cache the response", and the reason is a
single number:

> **1,440 requests per day, for the entire application.** Not per user. Not per environment.

A single Normal-mode playlist generation can spend three or four of those (artist search, setlists
list, setlist detail). Forty engaged users on a Saturday would exhaust the day for everyone,
including the developer trying to debug it. That is why `docs/architecture.md` §5 makes the cache
part of the architecture rather than an optimization applied later, why `CLAUDE.md` states the rule
as *"a cache miss is a budget decision"*, and why `docs/architecture.md` D-2 forbids CI from ever
touching setlist.fm.

Three properties make the problem tractable, and the design leans on all three:

1. **Past setlists are immutable history.** A show that happened on 3 May 2019 will never gain a
   song. Once fetched, that record is correct forever — so the durable cache tier is not a
   time-limited copy, it is the canonical store, and setlist.fm is the slow backfill behind it.
2. **The only volatile question is "has this band played since?"** — and it is date-bounded, cheap,
   and answerable for many bands in one scheduled pass instead of once per user per page view.
3. **Bands are shared, not user-scoped** (D-25, prompt 05). One user's fetch of a band's setlists
   benefits every other user who ever records a concert with that band. The cache's hit rate
   improves as the product grows, which is the opposite of how quota problems usually behave.

What this feature ships, then, is: a rate-limited, budget-aware, three-tier-cached client for
setlist.fm; a stable identity for bands (MusicBrainz IDs, not names); relational storage for
setlists and their songs; read-only API operations that serve from cache by default; a nightly
refresh job that is the *only* thing allowed to spend budget speculatively; and the instrumentation
that makes budget consumption visible in the backoffice before users notice it.

What it deliberately does **not** ship is anything that turns a song into something playable. No
matching, no provider, no playlist. This feature ends with "here are the songs, in order, as
setlist.fm recorded them" — prompt 12 picks up from there.

### Load-bearing rules this spec does not reverse

| Rule | Source | How this feature honours it |
|---|---|---|
| setlist.fm responses are always cached; a cache miss is a budget decision | `CLAUDE.md` | `SetlistFmClient` is `private`-by-convention behind `SetlistGateway`; no caller may reach the HTTP client without passing both cache tiers (US-6, AC-6.5) |
| CI runs no integration tests against real external APIs | `docs/architecture.md` D-2 | Recorded fixtures only; the one live test is `@group live`, excluded from the default suite (US-13) |
| Provider credentials never leave the secrets layer | `CLAUDE.md` | `SETLISTFM_API_KEY` is read from env, injected once, never logged, never rendered — asserted by test (US-12) |
| Every later feature is observable through the backoffice, not `psql` | `docs/architecture.md` §9 | Cache health and budget consumption land in the prompt-08 dashboard (US-11) |
| The OpenAPI spec is the single source of truth for endpoints | `CLAUDE.md` | New read operations are declared as API Platform resources; no endpoint list appears in this spec or any README |
| A user-scoped resource returns 404, never 403 | `CLAUDE.md`, D-27 | Setlist data is **not** user-scoped — it is shared reference data like `Band`. The rule is untouched because nothing here is owned (D-66) |

### Existing groundwork reused, not rebuilt

| Already in place | Where | Reused for |
|---|---|---|
| `Band.setlistfmMbid`, nullable, indexed | `backend/src/Entity/Band.php` | Band identity (US-1) — the column was added by prompt 05 precisely so this feature is a migration-*light* change |
| `BandResolver::normalize()` as a PHP service, not a DB function | `src/Service/Concert/BandResolver.php` | D-25 anticipated this feature replacing name-based identity with setlist.fm identity |
| `RateLimiterGuard` + Redis-backed limiters, fail-closed | `src/Service/Security/` | The 2/s token bucket is consumed through the same guard shape (US-7) |
| `AuditLogger`, `AbstractAdminCrudController` (abstract `configureFields`, D-46) | `src/Service/Admin/`, `src/Controller/Admin/` | Backoffice additions plug into the existing seams (US-11) |
| `DashboardController` with three uncached counts | `src/Controller/Admin/DashboardController.php` | Extended with budget and cache-health panels |
| `symfony/messenger`, `symfony/lock` | `composer.json` | Nightly refresh job and its single-runner lock (US-10) |
| `SETLISTFM_API_KEY`, `SETLISTFM_DAILY_BUDGET`, `SETLISTFM_RATE_PER_SECOND`, `SETLISTFM_CACHE_TTL` | `docs/env-vars.md`, `backend/.env.example` | Already declared — this branch makes them functional |
| `src/Service/Setlist/README.md` naming the intended classes | `src/Service/Setlist/` | The directory was reserved by prompt 01 for exactly this |

## Goals

| Goal | Success looks like |
|---|---|
| A band has a stable identity | A `Band` row carries a MusicBrainz ID once resolved, and every subsequent lookup uses the MBID rather than the typed name |
| Setlists are retrievable and complete | A band's past setlists are listable, newest first, and one setlist yields its songs in playing order with covers and inter-song notes intact |
| The cache does the work, not the API | In steady state the overwhelming majority of setlist reads are served without an outbound request; a repeated identical read makes **zero** — proven by test |
| The budget is never exceeded | The app cannot physically issue more than `SETLISTFM_RATE_PER_SECOND` per second or `SETLISTFM_DAILY_BUDGET` per day, across every web process and worker |
| Exhaustion is honest, not broken | When the budget is spent, reads return the best cached answer plus a machine-readable freshness signal — never a 500, never an empty list that reads as "this band has no setlists" |
| Failure upstream stays upstream | A 429 or a setlist.fm outage produces backoff and a degraded-but-working read path, not a retry storm and not a cascade into the product |
| Staying current is scheduled, not accidental | One nightly, budget-capped job answers "has this band played since?" for the bands that matter; no user request ever triggers a speculative refresh |
| The constraint is visible before it bites | Today's consumption against budget, the cache hit rate and cache size are on the backoffice dashboard |
| The key is invisible | No log line, API response, exception page or admin screen contains `SETLISTFM_API_KEY` — proven by asserting against output |

## User Stories

### US-1 — Resolve a band to a stable setlist.fm identity

> As a **user who recorded a concert**, I want the band I typed to be matched to the real band on
> setlist.fm, so that the setlists I am shown belong to the band I actually saw.

**Acceptance criteria**

- **AC-1.1** A band search operation accepts a free-text name and returns zero or more candidates,
  each carrying the setlist.fm MBID, the canonical name, disambiguation text and (where setlist.fm
  provides it) the sort name and an indication of how many setlists exist.
- **AC-1.2** Candidates are returned in setlist.fm's own relevance order; the API does not re-rank
  them, and does not silently drop any.
- **AC-1.3** Resolving a candidate writes its MBID onto the existing `Band` row
  (`Band::$setlistfmMbid`) and records `setlistfmResolvedAt`. The band's user-visible `name` is
  **not** overwritten by setlist.fm's canonical name (AC-8.4 of prompt 05 stands: the name is what
  the first creator typed); the canonical name is stored alongside as `setlistfmName`.
- **AC-1.4** Once a `Band` has an MBID, every subsequent setlist.fm call for that band uses the MBID
  and never re-runs a name search (D-56).
- **AC-1.5** Two `Band` rows can never hold the same non-null MBID — enforced by a partial unique
  index, so a normalization near-miss that produced two rows is detectable and mergeable rather than
  silently duplicated.
- **AC-1.6** A search for a name with no setlist.fm match records the outcome on the band as an
  explicit *checked, nothing found* state, not as "not yet checked" (see US-5).
- **AC-1.7** Band search results are cached (US-6) — searching the same string twice makes one
  outbound call.

### US-2 — Disambiguate when several bands share a name

> As a **user**, I want to say which "Nirvana" I mean when there is more than one, so that I do not
> get another band's setlists — and I want to be asked only once.

**Acceptance criteria**

- **AC-2.1** When a resolution attempt yields more than one plausible candidate, the band's
  resolution state becomes `ambiguous` and the API returns the candidate list rather than guessing.
- **AC-2.2** "More than one plausible candidate" is defined concretely: more than one candidate
  whose name normalizes (via the existing `BandResolver::normalize()`) to the same value as the
  query. Candidates that merely mention the query in their disambiguation text do not make a band
  ambiguous.
- **AC-2.3** A single exact normalized match auto-resolves with no user interaction, even if other,
  non-matching candidates were returned.
- **AC-2.4** The user's chosen MBID is stored on the shared `Band` row (D-57), so no user is asked to
  disambiguate a band that has already been resolved — including a user who has never seen that band
  before.
- **AC-2.5** A band left in the `ambiguous` state still functions everywhere else in the product
  (it appears in concerts, it can be listed) — ambiguity blocks setlist retrieval, nothing else.
- **AC-2.6** An operator can see the resolution state of every band in the backoffice, and can
  correct a wrong MBID (US-11). Correcting it clears the band's cached setlist associations so the
  next read repopulates from the right artist.

### US-3 — Read a band's past setlists

> As a **user**, I want to see the shows a band has played, most recent first, so that I can find
> the one I attended.

**Acceptance criteria**

- **AC-3.1** A read operation returns a band's setlists ordered by event date, newest first,
  paginated with the project's existing page-size bound (≤ 100, D-31).
- **AC-3.2** Each entry carries: setlist.fm setlist id, event date, venue name, city, country, tour
  name (nullable) and the number of songs.
- **AC-3.3** The operation is served from the durable cache tier whenever the band's setlist index
  has been fetched, with no outbound call (US-6).
- **AC-3.4** A band with an unresolved or ambiguous identity returns the appropriate explicit state
  (US-2, US-5), not an empty page.
- **AC-3.5** Pagination is over the **cached** setlist index, not proxied page-by-page to
  setlist.fm; a user paging through results does not spend one budget unit per page.
- **AC-3.6** Every response carries the freshness envelope defined in AC-8.3.

### US-4 — Read one setlist's songs

> As a **user**, I want the full song list of a specific show, in the order it was played, so that
> the playlist that comes later is the concert I actually attended.

**Acceptance criteria**

- **AC-4.1** A read operation returns one setlist's songs in playing order, with a stable 0-based
  position.
- **AC-4.2** Each song carries: title, position, the set it belonged to (main set index or encore),
  cover attribution (the original artist, when setlist.fm marks the song as a cover), guest
  performer info, and the free-text "info" note setlist.fm attaches to individual songs.
- **AC-4.3** Songs marked by setlist.fm as *tape* (played over the PA, not performed) are stored with
  that flag preserved and are **not** filtered out here — prompt 12 decides what to do with them.
- **AC-4.4** A setlist with an empty song list (setlist.fm has such records) returns an empty song
  array with an explicit `isEmpty` indication, and is distinguishable from "setlist not found".
- **AC-4.5** Once fetched, a setlist detail is served from the durable tier forever without a further
  outbound call (D-59) — proven by test.
- **AC-4.6** The verbatim setlist.fm JSON payload is retained on the cache row so a future change to
  the extraction rules can re-derive songs without re-spending budget.

### US-5 — A band with no setlist.fm presence says so plainly

> As a **user**, I want to be told that this band has no setlists on setlist.fm, so that I do not sit
> waiting for data that is never coming.

**Acceptance criteria**

- **AC-5.1** A resolution attempt that returns no candidates sets the band's state to `no_presence`
  and records when the check happened.
- **AC-5.2** A resolved band whose setlist index legitimately contains zero setlists is a distinct
  state from `no_presence` — the artist exists, the setlists do not.
- **AC-5.3** Both states are returned as a **200 with an explicit state field**, never a 404, never
  an error, never a bare empty array.
- **AC-5.4** A `no_presence` band is not re-checked on every read. Re-checking is the nightly job's
  business, and only for bands attached to an upcoming concert (US-10), with a minimum interval of
  30 days per band.
- **AC-5.5** A test asserts that the response for a `no_presence` band is distinguishable, by field
  value alone, from the response for a band whose data could not be fetched because the budget is
  exhausted (US-8).

### US-6 — A repeated read costs nothing

> As the **product owner**, I want identical reads to be free, so that the daily budget is spent on
> new information rather than on repetition.

**Acceptance criteria**

- **AC-6.1** Three tiers are checked in order (`docs/architecture.md` §5): Redis (short TTL) →
  PostgreSQL `setlist_cache` (durable) → setlist.fm.
- **AC-6.2** A hit in the durable tier **promotes** the entry into Redis so the next read inside the
  same session does not touch PostgreSQL either — verified by test.
- **AC-6.3** A miss that reaches setlist.fm writes to **both** tiers before returning.
- **AC-6.4** **Two identical reads produce exactly one outbound HTTP request** — asserted by a test
  with a mocked transport that counts calls. This is the single most important test in the feature.
- **AC-6.5** No class outside `App\Service\Setlist\` may hold a reference to `SetlistFmClient`; the
  only public entry point is `SetlistGateway`. Enforced by a static test over the container/wiring,
  not by convention (D-58).
- **AC-6.6** Cache keys are derived from the canonical outbound request (endpoint + normalized,
  sorted parameters), not from a caller-supplied string, so two different call sites asking the same
  question share one entry.
- **AC-6.7** Concurrent misses for the same key do not produce concurrent outbound calls: the fetch
  is guarded by a `symfony/lock` so the second caller waits for and reuses the first result
  (stampede protection), bounded by the same short wait as AC-7.5.

### US-7 — The app cannot exceed its rate limit or its daily budget

> As the **product owner**, I want the limits enforced in code across every process, so that no
> deployment topology or traffic spike can get the API key rate-limited or the day burned.

**Acceptance criteria**

- **AC-7.1** A Redis-backed token bucket enforces `SETLISTFM_RATE_PER_SECOND` (default 2) **globally**
  — shared by every web process and every Messenger worker, not per process.
- **AC-7.2** A Redis-backed daily counter enforces `SETLISTFM_DAILY_BUDGET` (default 1,440), keyed by
  UTC calendar date, expiring automatically after the day it covers.
- **AC-7.3** Both are consumed through a single gate (`SetlistFmBudget`); `SetlistFmClient` cannot
  issue a request without a token from it — no code path bypasses either limiter.
- **AC-7.4** A concurrency test issues many simultaneous requests and asserts that the observed
  outbound rate never exceeds the configured per-second limit and that the total never exceeds the
  daily budget.
- **AC-7.5** A caller that cannot obtain a token within a short bounded wait (default 1 second,
  configurable) does **not** block the request thread further: it degrades to cache with a
  `rate_limited` reason (D-62). Web requests never queue on the rate limiter.
- **AC-7.6** If Redis is unavailable, the gate **fails closed** — no outbound call is made and the
  read degrades to the durable tier (AC-8.3), matching `RateLimiterGuard`'s existing fail-closed
  posture. An unavailable limiter must never mean an unlimited limiter.
- **AC-7.7** Every retry attempt that actually reaches the network consumes budget and is counted;
  the accounting is of *requests issued*, not of *logical operations*.

### US-8 — Budget exhaustion degrades honestly

> As a **user**, I want to still see the setlists the app already has when its daily allowance is
> gone, and to be told plainly that fresh data is unavailable until tomorrow.

**Acceptance criteria**

- **AC-8.1** With the budget exhausted, a read that would have needed an outbound call returns the
  cached answer if there is one, with HTTP 200.
- **AC-8.2** With the budget exhausted and nothing cached, the read returns HTTP 200 with an explicit
  `unavailable` state — **not** 500, **not** 503, **not** an empty list presented as a result.
- **AC-8.3** Every setlist-bearing response carries a freshness envelope with at least:
  `source` (`live` | `cache`), `fetchedAt` (nullable ISO-8601), `stale` (bool) and `reason`
  (`null` | `budget_exhausted` | `rate_limited` | `upstream_unavailable`) (D-63).
- **AC-8.4** `budget_exhausted` responses include the UTC instant at which the budget resets, so the
  client can say "tomorrow at …" rather than "later".
- **AC-8.5** The freshness envelope is part of the generated OpenAPI schema, so the Expo client gets
  it as a typed field rather than an undocumented convention.
- **AC-8.6** A test drives the daily counter to its limit and asserts each of AC-8.1 through AC-8.4.
- **AC-8.7** Budget exhaustion is logged at `warning` **once per transition**, not once per request —
  a spent budget must not itself produce a log storm.

### US-9 — Upstream trouble does not cascade

> As the **product owner**, I want a setlist.fm 429 or outage to cost one degraded read rather than a
> retry storm that burns what budget is left.

**Acceptance criteria**

- **AC-9.1** The HTTP client sets an explicit connect and total timeout (`SETLISTFM_HTTP_TIMEOUT`,
  default 5s total), so a hanging upstream cannot hold a web worker.
- **AC-9.2** Retries apply only to transient failures (429, 5xx, connection/timeout) — never to 4xx
  other than 429, and never to a 404.
- **AC-9.3** Retries use exponential backoff **with jitter**, capped at a small number of attempts
  (default 2 retries), and honour a `Retry-After` header when setlist.fm supplies one.
- **AC-9.4** After N consecutive transient failures (default 5) a circuit breaker opens for a cooldown
  window; while open, no outbound call is attempted at all and reads degrade to cache with
  `upstream_unavailable` (D-64).
- **AC-9.5** A test simulates sustained 429s and asserts that the number of outbound attempts is
  bounded by the retry cap and the breaker — not multiplied by the number of concurrent callers.
- **AC-9.6** Breaker state is shared in Redis, so one process tripping it stops all of them.
- **AC-9.7** A setlist.fm error body is never forwarded to the client verbatim; the API returns the
  project's standard error/freshness shape only.

### US-10 — Setlists stay current without spending the budget on guesses

> As a **user with a concert coming up**, I want the band's newest setlists to be there when I look,
> without the app checking every band for every user all day.

**Acceptance criteria**

- **AC-10.1** A scheduled console command (`app:setlist:refresh`) runs nightly and refreshes the
  setlist index for bands in a bounded, prioritized set: bands appearing in concerts that are
  upcoming, or that ended within the last 7 days (D-65).
- **AC-10.2** The refresh is date-bounded — it asks only for setlists newer than the band's cached
  latest, and stops at the first fully cached page.
- **AC-10.3** The job spends at most a configurable share of the daily budget
  (`SETLISTFM_REFRESH_BUDGET_SHARE`, default `0.25`) and stops cleanly when that share is used,
  leaving the remainder for user-triggered reads.
- **AC-10.4** Bands are processed in priority order (nearest concert date first) so a truncated run
  still refreshes what mattered most.
- **AC-10.5** The job is guarded by `symfony/lock` so two overlapping runs cannot double-spend.
- **AC-10.6** No user-facing read path ever triggers a speculative "has this band played since?"
  check. User reads fetch what they need and nothing more.
- **AC-10.7** The job records its outcome (bands attempted, requests spent, entries written, budget
  remaining) in a form the backoffice can display (US-11).
- **AC-10.8** The job is idempotent and safe to run twice: a second run in the same night finds
  everything cached and spends almost nothing.

### US-11 — The operator can see the constraint

> As the **product owner**, I want today's budget consumption and the cache's effectiveness on the
> dashboard, so that I find out the cache is failing before my users do.

**Acceptance criteria**

- **AC-11.1** The prompt-08 dashboard gains a setlist.fm panel showing: requests used today against
  `SETLISTFM_DAILY_BUDGET` (absolute and percentage), the reset instant, and the current circuit
  breaker state.
- **AC-11.2** The same panel shows the cache hit rate for the current day and the trailing 7 days,
  broken down by tier (Redis hit / PostgreSQL hit / outbound), plus total durable cache entries and
  total stored songs.
- **AC-11.3** The last nightly refresh run's outcome (AC-10.7) is shown with its timestamp; a run
  that has not happened in over 36 hours is visibly flagged.
- **AC-11.4** A read-only backoffice section lists `setlist_cache` entries and lets an operator find
  a band's cached setlists — including each band's setlist.fm resolution state (AC-2.6).
- **AC-11.5** The operator can correct a band's MBID and can clear a band's setlist cache
  associations. Both are writes, so both go through the existing `AuditLogger` (D-43) and both are
  the *only* writes this feature adds to the backoffice (D-67).
- **AC-11.6** No panel, list, field or detail page renders `SETLISTFM_API_KEY` or any request header
  containing it — asserted against rendered HTML, as prompt 08's AC-10.3 does.
- **AC-11.7** Cache-health counters are read from Redis and are **uncached**, consistent with D-53;
  a dashboard that shows a stale budget figure is worse than no dashboard.

### US-12 — The API key is invisible

> As the **product owner**, I want the setlist.fm key to exist only in the secrets layer, so that no
> log, response, screen or exception page can leak it.

**Acceptance criteria**

- **AC-12.1** `SETLISTFM_API_KEY` is injected once into `SetlistFmClient` and is never stored in the
  database, never placed in a `ProviderSetting`-style row, and never passed as a query parameter.
- **AC-12.2** Request logging redacts the `x-api-key` header; a test asserts the key's value does not
  appear in the captured log output of a full request cycle, including on the failure paths.
- **AC-12.3** Exception messages, stack traces and the freshness envelope never include the outbound
  URL with headers; only the endpoint name and status code are recorded.
- **AC-12.4** A test asserts the key does not appear in any rendered admin response (AC-11.6) nor in
  the generated OpenAPI document.

### US-13 — The whole thing is green in CI, without calling setlist.fm

> As the **product owner**, I want the test suite to prove all of the above without spending a single
> live request, so that CI cannot take production down.

**Acceptance criteria**

- **AC-13.1** Every test in the default suite uses a mocked HTTP transport and recorded fixtures
  captured from real setlist.fm responses; the default suite makes zero outbound calls
  (`docs/architecture.md` D-2).
- **AC-13.2** A test fails the build if `SETLISTFM_API_KEY` is set to a real-looking value in the test
  environment configuration.
- **AC-13.3** One live smoke test exists, tagged `@group live`, excluded from the default suite and
  from CI, documented with how and when to run it manually.
- **AC-13.4** Fixtures cover: multi-candidate search, single-match search, empty search, a large
  setlist index, a setlist with covers/tape/encores, an empty setlist, a 429 with `Retry-After`, and
  a 500.
- **AC-13.5** The feature's tests run against real Redis and real PostgreSQL from `compose.yaml`,
  not in-memory doubles — cache-tier promotion and the shared limiter are exactly the behaviours a
  double would fake away.

## Technical Approach

### Backend (`backend/`) — the only sub-project touched

| Area | Work |
|---|---|
| HTTP | `symfony/http-client` scoped client for setlist.fm (base URI, `x-api-key`, `Accept: application/json`, timeouts) |
| Services | `App\Service\Setlist\` — `SetlistGateway` (the only public entry point), `SetlistFmClient` (transport), `SetlistCache` (two-tier read/write + promotion), `SetlistFmBudget` (token bucket + daily counter + breaker), `BandIdentityResolver` (search → MBID → `Band`), `SetlistNormalizer` (raw JSON → entities) |
| Entities | `SetlistCacheEntry` (new), `Setlist` (new), `Song` (new). `Band` gains `setlistfmName`, `setlistfmResolutionState`, `setlistfmCheckedAt`, `setlistfmResolvedAt` |
| API | New read-only API Platform resources + state providers for band search, a band's setlists, and one setlist's songs. All read operations; no writable setlist resource exists |
| Console | `app:setlist:refresh` (nightly, locked, budget-capped) |
| Messenger | Scheduled trigger for the refresh command; no per-request async work |
| Admin | Dashboard panels (budget, hit rate, last run), `SetlistCacheEntryCrudController` (read-only) and two audited actions on bands (`AbstractAdminCrudController`, `AuditLogger`) |
| Migration | One migration: `setlist_cache`, `setlists`, `songs`, four `bands` columns, a partial unique index on `bands.setlistfm_mbid` |
| Config | New rate-limiter entries, new env vars (below) |

### Data model sketch

```
Band (existing, extended)
  id, name, normalizedName                       ← prompt 05, unchanged
  setlistfmMbid          string(64) null, UNIQUE WHERE NOT NULL
  setlistfmName          string(200) null        ← setlist.fm's canonical name
  setlistfmResolutionState enum: unresolved | resolved | ambiguous | no_presence
  setlistfmCheckedAt     timestamptz null        ← when we last looked
  setlistfmResolvedAt    timestamptz null        ← when an MBID was chosen

SetlistCacheEntry            ← the durable tier: verbatim upstream responses
  id
  cacheKey     string, UNIQUE   ← endpoint + canonical sorted params (AC-6.6)
  endpoint     string           ← 'artist.search' | 'artist.get' | 'artist.setlists' | 'setlist.get'
  payload      JSONB            ← the raw setlist.fm response (AC-4.6)
  fetchedAt    timestamptz
  staleAfter   timestamptz null ← NULL = immutable, never re-fetch (D-59)
  httpStatus   smallint

Setlist                      ← the queryable projection of a cached setlist
  id
  setlistfmId  string, UNIQUE
  band_id      FK → Band
  eventDate    date
  venueName / venueCity / venueCountry   string null
  tourName     string null
  songCount    smallint
  isEmpty      bool
  fetchedAt    timestamptz

Song                         ← ordered children of a Setlist
  id
  setlist_id   FK → Setlist (ON DELETE CASCADE)
  position     smallint         ← 0-based, playing order (AC-4.1)
  setLabel     string null      ← 'Encore 1', main-set index, …
  title        string
  coverOfName  string null      ← original artist when marked a cover
  coverOfMbid  string(64) null
  withName     string null      ← guest performer
  info         text null        ← setlist.fm's per-song note
  isTape       bool             ← preserved, not filtered (AC-4.3)
  UNIQUE (setlist_id, position)
```

`SetlistCacheEntry` is the source of truth for *what setlist.fm said*; `Setlist`/`Song` are the
derived, queryable shape. Keeping both is deliberate — see D-60.

### New environment variables

| Variable | Secret | Default | Purpose |
|---|---|---|---|
| `SETLISTFM_API_KEY` | **yes** | — | Already declared |
| `SETLISTFM_DAILY_BUDGET` | no | `1440` | Already declared |
| `SETLISTFM_RATE_PER_SECOND` | no | `2` | Already declared |
| `SETLISTFM_CACHE_TTL` | no | `300` | Already declared — Redis tier TTL, seconds |
| `SETLISTFM_BASE_URL` | no | `https://api.setlist.fm/rest/1.0` | **New** — so tests and the live smoke test can point elsewhere without code changes |
| `SETLISTFM_HTTP_TIMEOUT` | no | `5` | **New** — total request timeout, seconds (AC-9.1) |
| `SETLISTFM_TOKEN_WAIT` | no | `1` | **New** — max seconds a request will wait for a rate-limit token before degrading (AC-7.5) |
| `SETLISTFM_REFRESH_BUDGET_SHARE` | no | `0.25` | **New** — share of the daily budget the nightly job may spend (AC-10.3) |

All new variables go into `docs/env-vars.md` **and** `backend/.env.example` in the same commit.

### Decisions

Numbered from **D-56**; `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9` belong to
the backend skeleton, `D-10`–`D-17` to the frontend skeleton, `D-18`–`D-23` to auth, `D-24`–`D-31`
to the concert domain, `D-32`–`D-41` to the concert tracker UI and `D-42`–`D-55` to the backoffice
foundation.

**D-56 — MBID is the band's identity everywhere; the typed name is only ever a lookup hint.**
Band names are not unique and never will be — the prompt is right that identity here is genuinely
ambiguous. Once a `Band` carries an MBID, no code path may re-derive identity from
`normalizedName`; every setlist.fm call is by MBID. `normalizedName` (D-25) keeps its job of
deduplicating rows *before* setlist.fm has been consulted, and nothing more. The partial unique index
on `setlistfm_mbid` (AC-1.5) means the database, not a service, guarantees one row per real band once
resolved. Cost accepted: two `Band` rows created under different spellings can both exist until one
resolves to an MBID already held by the other, at which point the resolution fails loudly and an
operator merges them — a visible, correctable state rather than a silent double.

**D-57 — The disambiguation choice is stored on the shared `Band`, not per user.**
`Band` is global (D-25, `CLAUDE.md` glossary), so the choice must be too, otherwise every user pays
the same question and the cache fragments per user. First resolver wins. The obvious objection is
that one user's mistake becomes everyone's mistake — accepted, because the alternative (a per-user
band identity) duplicates the entity the whole product treats as shared, and multiplies budget
consumption by the number of users. The mitigation is that a wrong choice is *visible and
correctable in one place*: AC-2.6 gives the operator the fix, audited. A per-user override, if it is
ever genuinely needed, is an additive change to a table that does not exist yet.

> **Widened on 2026-08-27 by D-270 – D-272** (`docs/specs/2026-08-27-instant-setlist-refresh.md`):
> the set of actors who may make this choice widens from "an operator only" to "an operator, or an
> entitled user picking from a server-produced candidate set, into a vacant identity, once." What is
> stored — one MBID on the shared `Band`, first-write-wins — does not change; this paragraph's own
> mitigation (visible and correctable in one place) is exactly what the amendment leans on, not what
> it revises. See R-4 below for the widened mitigation note.

**D-58 — `SetlistGateway` is the only door; the HTTP client is not injectable elsewhere.**
`CLAUDE.md`'s "always cached" rule is only as strong as its weakest caller. Rather than trusting
future features to remember, the client is registered as a private, non-aliased service consumed
solely by the cache layer, and a test walks the container asserting nothing else depends on it
(AC-6.5) — the same structural-enforcement move as D-46's abstract `configureFields`. This is the
setlist.fm analogue of the streaming port rule: one seam, no side doors.

**D-59 — Two freshness classes, decided by the data's nature, not by a global TTL.**
Immutable data (a specific past setlist's detail; a page of a band's setlist index whose entries are
all in the past) is stored with `staleAfter = NULL` and is **never** re-fetched — this is what makes
the budget survivable. Volatile data (artist search results, the first page of a band's index, which
can gain entries) carries a `staleAfter`. The Redis tier keeps `SETLISTFM_CACHE_TTL` for everything
because its only job is absorbing repeats within a session. The alternative — one TTL for
everything — either re-fetches immutable history for no reason or serves search results from 2019.

**D-60 — Setlists are stored twice, on purpose: verbatim JSONB *and* relational rows.**
The JSONB payload is the receipt: it is what setlist.fm actually returned, and it means a later
change to how songs are parsed (prompt 12 will want things this spec has not thought of) is a
re-derivation, not a re-fetch — the difference between free and a budget catastrophe. The relational
`Setlist`/`Song` rows exist because matching, ordering and counting songs are queries, and doing them
through JSONB operators would push provider-agnostic logic into PostgreSQL syntax. The cost is
storage duplication and a normalizer that must stay correct; both are cheap against 1,440 requests
a day.

**D-61 — Rate limit and daily budget are one gate, in Redis, fail-closed.**
Two separate limiters that callers must remember to consume in the right order is a bug waiting for
its first new call site. `SetlistFmBudget` exposes a single `acquire()` that consumes the token
bucket *and* the daily counter atomically-enough (counter first, so a rejected day never even queues
for a token) and returns a typed refusal reason that flows straight into the freshness envelope. Both
live in Redis so the limit is application-wide rather than per process — a per-process limiter on
three workers is a 6/s limiter, which is a banned key. Redis unavailable means **no outbound call**
(AC-7.6), matching `RateLimiterGuard`'s existing posture: a limiter that fails open is not a limiter.

**D-62 — A web request never queues on the rate limiter.**
With a 2/s global bucket, ten simultaneous users would mean a five-second wait for the last one, and
under FrankenPHP that is five seconds of a held process. So the wait is bounded
(`SETLISTFM_TOKEN_WAIT`, default 1s) and expiry degrades to cache with `rate_limited`. Slightly stale
data now beats fresh data after a timeout. The nightly job, which is not user-facing, may wait
properly — it is the one caller allowed to be patient.

**D-63 — Degradation is a first-class field, not an HTTP status.**
Every setlist-bearing response carries `{source, fetchedAt, stale, reason}` (AC-8.3). Using status
codes instead (503 for exhausted, 204 for nothing) would make the client's error path carry product
meaning, and would make a perfectly good cached answer look like a failure. 200 + an explicit reason
lets the Expo client render "showing setlists from yesterday — fresh data available tomorrow at
00:00 UTC" without inventing the vocabulary itself. `docs/external-apis.md` requires exactly this:
*"never return an empty result as though the band had no setlists"*.

**D-64 — A shared circuit breaker, because retries spend real budget.**
Retries are not free here the way they usually are — every attempt that reaches the network consumes
one of 1,440 (AC-7.7). So retries are capped and jittered, `Retry-After` is honoured, and after five
consecutive transient failures a Redis-shared breaker opens for a cooldown, during which zero calls
are attempted. Without the shared state, ten processes each discover the outage independently and
spend fifty requests learning the same thing.

**D-65 — Freshness is a nightly, prioritized, budget-capped job — never an on-demand check.**
> **Narrowed on 2026-08-27 by D-254** (`docs/specs/2026-08-27-instant-setlist-refresh.md`). Everything
> below remains the rule for **the default, unentitled path** — which is every user unless an operator
> deliberately grants otherwise. A single entitled, band-scoped, cooldown-bounded, per-user-capped
> exception now exists, and it spends from this same budget through this same gate. Read D-254 with
> this decision, not instead of it.

*This resolves the prompt's open question.* The refresh policy is: one scheduled run per night, over
bands attached to concerts that are upcoming or ended in the last 7 days, nearest-first, spending at
most 25% of the day's budget. On-demand per-user checks are rejected outright: they scale with
traffic (exactly the thing the budget cannot absorb), they ask the same question repeatedly for the
same band, and their cost is paid in user-visible latency. The nightly job's cost is bounded,
predictable, prioritized toward the bands users are about to care about, and truncatable without
losing correctness. The trade-off: a setlist published this morning may not appear until tomorrow.
For a product about concerts you attended, that is acceptable; for the 7-day window after a show
(when the setlist typically gets uploaded), the job covers it.

**D-66 — Setlist data is shared reference data, not a user-scoped resource.**
Setlists, songs and bands are facts about the world, identical for everyone, and nothing here is
owned. So the 404-not-403 ownership gate (D-27, `CLAUDE.md`) does not apply and — importantly — is
not extended, weakened or referenced. The operations are authenticated (a logged-in user only, so
the budget cannot be drained anonymously) but not owner-filtered. No `ConcertOwnerExtension`-shaped
extension is added for these resources; adding one would imply an ownership model that does not
exist and would confuse the invariant that does.

**D-67 — The backoffice gets read-only views plus exactly two audited writes.**
> **Narrowed on 2026-08-27 by D-255** (`docs/specs/2026-08-27-instant-setlist-refresh.md`). The
> backoffice half is **unchanged and permanent**: there is still no "refresh this band now" button in
> `/admin`. What changed is the API: an entitled user may trigger one band-scoped refresh, and it is
> not the "one click, no ceiling" this decision rejected — it passes a per-band cooldown, a per-user
> daily cap and an application budget reserve before it reaches the budget gate.

Cache-health, budget and cache-entry views are read-only, consistent with prompt 08's posture
(D-46, US-6 there). The two exceptions are correcting a band's MBID and clearing a band's cached
setlist associations (AC-11.5) — both necessary because D-57's first-resolver-wins model needs a
correction path to be defensible, and both routed through `AuditLogger`. Notably absent: a "refresh
this band now" button. It would be a one-click budget spend on the most dangerous resource in the
product, and the nightly job already covers the need.

**D-68 — Cache and budget metrics live in Redis, not in a table.**
Hit/miss counters are per-day Redis counters with a short expiry (7 days, for AC-11.2's trailing
window). They are operational telemetry, not domain data: writing a row per cache read would make
the cache slower than the thing it is caching, and the numbers are worthless a week later. The cost
is that metrics vanish if Redis is flushed — acceptable for a dashboard, and the durable counts
(cache entries, songs) come from PostgreSQL anyway.

**D-69 — The budget ceiling is configuration, not a constant.**
`SETLISTFM_DAILY_BUDGET` and `SETLISTFM_RATE_PER_SECOND` are already env vars and stay that way, so
a granted higher tier (16/s, 50,000/day) is an environment change rather than a code change. No
literal `1440` or `2` appears anywhere outside the default declaration. This is also why applying
for the higher tier is an operational action that nothing in this branch waits on (R-2).

**D-70 — Fixtures are recorded from real responses once, by hand, and committed.**
CI never calls setlist.fm (D-2), so the fidelity of the fixture set *is* the fidelity of the tests.
They are captured deliberately (AC-13.4 enumerates the required cases), committed, and the single
live smoke test (AC-13.3) exists to catch the day setlist.fm's shape changes underneath them. That
smoke test is run before a release, manually, not on a schedule — a scheduled live test is a
scheduled budget spend.

---

#### Amendment — 2026-08-27

The two decisions below were added after this spec shipped, by
`docs/specs/2026-08-27-instant-setlist-refresh.md`, prompted by
`docs/investigations/2026-08-27-boikot-setlist-not-found.md`. They **narrow** D-65 and D-67; they do
not reverse them, and they leave D-56, D-57, D-58, D-59, D-60, D-61, D-62, D-63, D-64, D-66, D-68,
D-69 and D-70 untouched. In particular the caching, identity and budget mechanics are unchanged: the
new path spends from the same 1,440/day pool, through the same `SetlistFmBudget::acquire()`, and is
refused when the budget is spent exactly as every other caller is. Nothing above has been deleted —
D-65 and D-67 stand as written, with a scope note.

**D-254 — There is exactly one on-demand exception, and it is paid for with throttles, not with a
quota.**
D-65 rejected on-demand checks because *they scale with traffic*. That argument is correct, and the
exception is built so that it does not: an **entitled** user (a deliberately granted, audited,
per-account flag — not an admin, not every user) may trigger a refresh for **one band on one of their
own concerts**, and only after a per-band cooldown, a per-user daily cap and an application budget
reserve have all been cleared. Its cost therefore scales with `min(entitled users × daily cap,
remaining budget above the reserve)` — configuration, not traffic. It is not speculative (a human
asked for it by name, so AC-10.6's rule that no *read path* triggers a check is untouched), not
repeated (the cooldown is band-scoped, because the band is shared and the second user's question
costs the same units as the first's), and not privileged: **no separate quota, no reserved lane, no
bypass.** The rejected alternative — giving entitled users their own request allowance — is exactly
the second counter D-61 warns about. Cost accepted and not disguised: on a busy day an entitled
user's refresh can be the request that exhausts the budget for an unentitled one. The reserve bounds
how much of the day that can be; it does not eliminate it.

**D-255 — The narrowing is to the API only; the backoffice still gets no "refresh now" button.**
D-67's *"notably absent"* stands for `/admin`, permanently. Operators already have the two audited
writes that matter — MBID correction and cache clear — and an operator who wants fresher data runs
`app:setlist:refresh`. A third backoffice write with no user need behind it would be scope, not
capability. What changed is one sentence's reach: *"on-demand per-user checks are rejected outright"*
becomes *"…rejected outright on the default path"*. The reasoning underneath both decisions — that
the budget is the most dangerous resource in the product and a one-click unbounded spend against it
is unacceptable — is not weakened; it is what dictated every throttle in D-254.

**D-270 – D-272 — the user-side disambiguation pick, added 2026-08-27** (full text in
`docs/specs/2026-08-27-instant-setlist-refresh.md`; summarized here as the pointer this document's
own convention keeps for every amendment):

- **D-270** — A user may fill an empty band identity (`setlistfmMbid IS NULL`); they may never
  overwrite one. This is what keeps D-56 ("once resolved, never re-derived") whole under the
  widened chooser set — the pick is the *first* derivation, made by a human, never a second one.
- **D-271** — The user chooses from a server-produced candidate set — the exact list their own most
  recent refresh returned — never a free-text MBID. This is what keeps D-57's blast radius bounded
  under a wider set of choosers: the reachable wrong answers shrink from "every MBID in existence"
  to "the handful of same-named bands setlist.fm's own search proposed."
- **D-272** — Any returned candidate is selectable, not only ones whose name normalizes exactly to
  the band's (the auto-resolver's own conservatism, AC-2.3, is unchanged and still governs what the
  *machine* may decide unaided).

These three decisions widen who may exercise D-57's write; they do not touch what is written, how
it is stored, or D-56/D-58/D-59's guarantees about identity and the transport door.

---

### Suggested implementation order

1. **Fixtures first.** Capture the AC-13.4 response set from setlist.fm by hand (a handful of live
   requests, spent once, deliberately) and commit them. Everything after this is offline.
2. `SetlistFmBudget`: token bucket, daily counter, breaker, fail-closed behaviour, with the
   concurrency test (AC-7.4). The gate exists before anything can call through it.
3. `SetlistFmClient`: scoped HTTP client, timeouts, retry/backoff/`Retry-After`, 429 handling
   (US-9), consuming the gate. Key redaction (US-12) lands here, not later.
4. Migration + `SetlistCacheEntry`, `Setlist`, `Song`, and the four `Band` columns.
5. `SetlistCache`: two-tier read, promotion, stampede lock, freshness classes (D-59). The
   zero-outbound-call test (AC-6.4) and the promotion test (AC-6.2) land with it.
6. `SetlistGateway` + the container test that nothing else can reach the client (AC-6.5).
7. `BandIdentityResolver`: search, auto-resolve, ambiguity, `no_presence` (US-1, US-2, US-5).
8. `SetlistNormalizer` + the read operations and their state providers, including the freshness
   envelope in the generated schema (US-3, US-4, US-8).
9. `app:setlist:refresh` + its scheduler wiring, lock and budget cap (US-10).
10. Backoffice panels, the read-only cache section and the two audited band actions (US-11).
11. Documentation updates and `/doc-check` before the PR.

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **Song-to-track matching** — turning a `Song` into a provider `Track` | Prompt 12 (spike) and beyond. This feature stops at "these are the songs setlist.fm recorded" |
| **Playlist creation** | Prompt 14 |
| **Any streaming provider adapter** | Prompt 10. No `Spotify`/`YouTube`/`Apple` symbol appears anywhere in this branch (`CLAUDE.md`) |
| **Rich band metadata** — photos, bios, genres, related artists | Prompt 24 |
| **A UI for any of this** | No frontend work in this branch. Band search and setlist screens arrive with the playlist flows (prompts 15–17). The client types are regenerated but not consumed |
| **Writing to setlist.fm** — submitting or editing setlists | Not a product goal, and it would change the API terms conversation entirely |
| **Venue enrichment from setlist.fm** into the `Venue` embeddable (D-26) | Tempting and cheap-looking, but it makes user-entered data and upstream data fight over one field. Needs its own decision; prompt 24 owns venue promotion |
| **Automatic band merge** when two rows resolve to one MBID | AC-1.5 makes the collision *visible*; merging user-attached rows automatically is a data-loss risk. Prompt 08's tooling territory |
| **A user-facing "refresh now" control**, and its backoffice equivalent | D-67. One click, one budget unit, no ceiling. **Amended 2026-08-27:** the backoffice equivalent stays out of scope permanently (D-255); the user-facing control moved *in* scope, entitlement-gated and throttled, in `docs/specs/2026-08-27-instant-setlist-refresh.md` (D-254) |
| **Per-user quota enforcement** on setlist reads | Prompt 22 (entitlement and quota seam). This feature enforces the *application's* budget; per-user fairness is a separate seam |
| **Applying for the higher rate tier** | An operational action (R-2), not code. Nothing here blocks on it |
| **A commercial-use agreement with setlist.fm** | `docs/external-apis.md`'s monetization checklist; prompt 23. The product is unmonetized, so the free terms hold today |

## Dependencies

**Must be true before implementation begins**

| Dependency | Provides | Status |
|---|---|---|
| **Prompt 05 merged — concert domain API** | `Band` (shared, `normalizedName`-deduplicated, with the nullable `setlistfm_mbid` column and index already in place), `BandResolver::normalize()` as a replaceable PHP service, `ConcertBand` lineup rows, `Concert.pastAfter` — which is what makes AC-10.1's "upcoming or ended in the last 7 days" query one indexed comparison | **Met** |
| **Prompt 08 merged — backoffice foundation** | `DashboardController` to extend, `AbstractAdminCrudController` with abstract `configureFields` (D-46) so the new cache section cannot leak fields, `AuditLogger` as the single write path for AC-11.5, the 2FA-gated admin firewall, and the established rule that new features are observable through the backoffice | **Met** |
| A valid setlist.fm API key for the developer's environment | The fixture capture in step 1 and the live smoke test | **To confirm** — needed before step 1; nothing else in the branch needs it |
| Redis reachable, shared across processes | Token bucket, daily counter, breaker, cache tier 1, metrics | **Met** (compose `redis`, healthchecked) |
| `symfony/lock` and `symfony/messenger` installed | Stampede protection (AC-6.7), nightly job lock and scheduling (AC-10.5) | **Met** (`composer.json`) |
| `symfony/http-client` available with retry support | `SetlistFmClient` | **To verify** — trivial, but confirm the retry strategy hooks needed for AC-9.3 exist in the installed version |
| A scheduling mechanism in the deployment target | AC-10.1's nightly run | **To confirm** — Messenger's scheduler covers local and PaaS cron; the deployment doc needs the entry |

**Depended on by**

- **Prompt 12 (song matching spike)** — consumes `Song` rows; D-60's verbatim payload is what lets it
  re-derive fields this spec did not anticipate without spending budget.
- **Prompt 14 / 17 (playlist fast and normal mode)** — Normal mode is *"the user picks the setlist,
  then picks song versions"* (`CLAUDE.md` glossary); the setlist-picking half is this feature's read
  operations.
- **Prompt 22 (entitlement and quota seam)** — per-user quotas exist because of this budget;
  `SetlistFmBudget` is the application-wide half of that story.
- **Prompt 24 (rich metadata)** — inherits the MBID as the join key to MusicBrainz.

**Assumptions** *(labelled as assumptions, not verified facts)*

- setlist.fm's artist search returns MBIDs for essentially all artists that have setlists; bands
  without an MBID are rare enough to be treated as `no_presence`.
- setlist.fm's `Retry-After` header is present on 429s. If it is not, AC-9.3's fixed backoff schedule
  applies and nothing else changes.
- A band's setlist index page size (setlist.fm's default 20) is stable; AC-3.5 decouples our
  pagination from theirs, so a change in their page size is a cache-population detail, not a contract
  change.
- The 7-day post-concert window (AC-10.1) is long enough for a setlist to be uploaded. This is a
  guess informed by nothing measurable yet; it is a config-shaped number and should be revisited once
  there is real usage.
- The nightly job's 25% share is sufficient for MVP volume. Both numbers exist to be tuned from the
  dashboard (US-11), which is the point of building the dashboard in the same branch.

## Risks & Open Questions

| # | Risk | Impact | Mitigation / decision |
|---|---|---|---|
| R-1 | **The 1,440/day budget is the single biggest scaling constraint in the product** — and it is shared with the developer's own testing | Existential at any real user count | Instrumented from day one (US-11), not added after the first outage. The cache is designed around immutability (D-59) so steady-state consumption is dominated by *new* bands and *new* shows, not by traffic. If the dashboard shows the hit rate below ~90% in normal use, the cache design is wrong and must be revisited before user growth, not after |
| R-2 | **The higher rate tier (16/s, 50k/day) is not granted, or is granted late** | Medium — caps growth, does not block this branch | Applying is an operational action, not a code dependency (D-69): both limits are env vars, so a grant is a config change. Apply now; build as if the answer is no |
| R-3 | **Band identity is genuinely ambiguous** — shared names, tribute bands, reunions under variant spellings | High: wrong identity means wrong setlists, which means a wrong playlist, which is the product failing at its one job | MBID everywhere (D-56), stored disambiguation (D-57), a DB-level uniqueness guarantee (AC-1.5), and an operator correction path (AC-11.5). Ambiguity is a *visible state*, never a silent guess (AC-2.1) |
| R-4 | **A wrong shared disambiguation propagates to every user** (the cost of D-57) | Medium | Accepted deliberately. Auto-resolution requires an exact normalized match (AC-2.3), so the automatic path is conservative; anything less certain asks. The correction is one audited operator action and takes effect for everyone at once — the same property that makes the mistake shared makes the fix shared. **Widened on 2026-08-27 (D-270 – D-272, `docs/specs/2026-08-27-instant-setlist-refresh.md`):** the *chooser* set widens from "an operator only" to "an operator, or an entitled user picking from a server-produced candidate set, into a vacant identity, once" — the severity and the fix are unchanged, the likelihood is what rises, and that amendment's own R-11 carries the fuller accounting (candidate-set-only, vacancy-only, once-only, an ownership gate, and a `choose_band_mbid` audit entry the operator's correction never had) |
| R-5 | **The cache is bypassed by a future call site** that injects the HTTP client directly | High — silently reintroduces the problem this whole feature solves | Structural, not procedural: the client is private and a container test asserts nothing outside `Service/Setlist/` depends on it (AC-6.5, D-58) |
| R-6 | **A retry storm burns the remaining budget during an upstream incident** | High — an outage becomes a day-long outage | Capped, jittered retries; `Retry-After` honoured; a Redis-shared circuit breaker (D-64); AC-9.5 tests the bound explicitly |
| R-7 | **Redis unavailability degrades everything at once** — no limiter, no tier-1 cache, no metrics | Medium | Fail-closed (AC-7.6): reads fall through to PostgreSQL and return `upstream_unavailable`. Degraded but correct, and it cannot become an unlimited-request state |
| R-8 | **`Setlist`/`Song` drift from the JSONB payload** after a normalizer change | Medium | D-60 keeps the payload verbatim, so re-derivation is a local migration command rather than a re-fetch. Worth adding that command the first time the normalizer changes; not needed in this branch |
| R-9 | **The nightly job silently stops running** and data quietly goes stale | Medium and quiet | AC-11.3 surfaces the last run on the dashboard and flags a gap over 36 hours. A missing run is visible, not inferred |
| R-10 | **Fixture drift** — setlist.fm changes response shapes and the mocked suite stays green | Medium | The `@group live` smoke test (AC-13.3, D-70), run manually before releases. Accepted: CI cannot catch this, and D-2 says it must not try |
| R-11 | **Cache growth is unbounded** — every setlist ever fetched is kept forever | Low | Intentional: the data is immutable and small (a setlist is a few KB of JSONB), and re-fetching to save disk would trade the cheap resource for the scarce one. Revisit only if `setlist_cache` becomes operationally awkward, and even then prune the JSONB payload, never the relational rows |
| R-12 | **Scope creep into matching** — "while we have the songs, let's just try Spotify" | Medium | The Out of Scope table is binding. This branch contains no provider symbol at all, which is also a `CLAUDE.md` rule and therefore reviewable in the diff |

**Open questions — resolved by the user on approval**

1. **The 7-day post-concert refresh window and the 25% budget share** (AC-10.1, AC-10.3) — confirmed
   as sensible starting points. Kept as-is, env-configurable.
2. **Should band search be reachable before a concert is created?** — **Yes, standalone.** Band
   search is authenticated but not tied to owning or creating a concert (as D-66 already specified),
   explicitly to leave it available for future features beyond concert creation.

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/env-vars.md`** *and* **`backend/.env.example`** — the four new variables
  (`SETLISTFM_BASE_URL`, `SETLISTFM_HTTP_TIMEOUT`, `SETLISTFM_TOKEN_WAIT`,
  `SETLISTFM_REFRESH_BUDGET_SHARE`), with a note that raising `SETLISTFM_DAILY_BUDGET` is valid only
  after setlist.fm grants a higher tier. Both files or neither.
- **`docs/architecture.md`** — record **D-56**–**D-70**; update §5 to describe what actually shipped
  (freshness classes, the gate, the breaker, the nightly job) and §9 with the new backoffice panels.
- **`docs/external-apis.md`** — no terms change, but record that the daily budget is now enforced
  in-application and observable, and note the higher-tier application as an outstanding action with
  its current status.
- **API Platform resources** — the new read operations and the freshness envelope schema regenerate
  the OpenAPI document, which is the only place endpoints are described (`CLAUDE.md`). **No endpoint
  list in this spec or in any README.**
- **Root `README.md`** — the nightly refresh command in the operations section, and how to run the
  `@group live` smoke test.
- **`frontend/README.md`** — no change. The frontend is untouched in this branch; types are
  regenerated when prompt 15/16 consumes them.
- **`CLAUDE.md`** — consider a short addendum to the existing "setlist.fm responses are always
  cached" rule naming `SetlistGateway` as the only door (D-58), in the same spirit as the streaming
  port rule. Recommended: yes.

---

**Review requested.** This spec proposes decisions **D-56**–**D-70** and is not implementable until
approved. The three most consequential — and the three most worth disagreeing with — are **D-57**
(one shared disambiguation choice for all users, wrong for everyone if wrong once), **D-65** (a
nightly, budget-capped refresh instead of on-demand freshness, which means a setlist uploaded this
morning may not appear until tomorrow) and **D-60** (setlists stored twice, verbatim JSONB plus
relational rows, trading storage for never having to re-spend budget). The two open questions above
are the only things deliberately left undecided.
