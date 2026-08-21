# 09 — setlist.fm integration

**Command:** `/feature setlistfm-integration` · **Agent:** `backend-engineer` · **Depends on:** 05, 08

## Goal
Setlistify can find a band on setlist.fm, resolve it to a stable identifier, and fetch that band's
past setlists — while consuming a small enough share of a **1,440 requests/day** budget that the app
can serve more than a handful of users.

## Context
**Read `docs/external-apis.md` §setlist.fm and `docs/architecture.md` §5 before writing anything.**

The standard API key allows 2 requests/second and 1,440 requests/day **for the entire application**,
not per user. This single number is why the cache in this prompt is mandatory rather than an
optimization, and why the design below looks heavier than a simple HTTP client.

The saving grace: past setlists are immutable history. Once fetched, a setlist never changes. Only
"has this band played since?" needs refreshing, and that query is date-bounded.

## Scope
- `SetlistFmClient`: typed HTTP client for artist search, artist lookup by MBID, and setlists by
  artist. Timeouts, retry with exponential backoff, and correct 429 handling.
- **Rate limiting**: a token bucket at `SETLISTFM_RATE_PER_SECOND` (2/s) *and* a daily counter against
  `SETLISTFM_DAILY_BUDGET` (1,440), both in Redis and shared across all workers and web processes.
- **Three-tier cache**, per `docs/architecture.md` §5: Redis (short TTL) → PostgreSQL
  `setlist_cache` (durable, JSONB payload + `fetched_at`) → the API. Nothing calls setlist.fm without
  passing both tiers first.
- `SetlistCacheEntry` and `Song` entities: setlist.fm id, event date, venue, tour, and the ordered
  songs including cover attribution and inter-song info.
- Band resolution: attach setlist.fm MBIDs to the `Band` entity from prompt 05, with a
  disambiguation path when several artists match a name.
- API endpoints: search bands, list a band's setlists (paginated), fetch one setlist's songs — all
  served from cache wherever possible.
- **Budget-exhausted behaviour**: serve cached data and return an explicit, machine-readable "fresh
  data unavailable until the budget resets" signal. Never an empty list, never a 500.
- Backoffice additions (extending prompt 08): today's request count against budget, cache hit rate,
  and cache entry counts — so quota exhaustion is diagnosable at a glance.
- Tests: rate limiter under concurrency, budget exhaustion, cache tier promotion, 429 backoff, and
  graceful degradation. External calls mocked; one optional live smoke test that is **not** run in CI.

## Out of scope
- Song-to-track matching — prompt 12 (spike) and beyond.
- Playlist creation — prompt 14.
- Any streaming provider — prompt 10.
- Rich band metadata such as photos — prompt 24.

## Acceptance criteria
- [ ] Searching a band returns candidates with setlist.fm identifiers, and resolves to a `Band` row.
- [ ] A band's setlists are fetchable, ordered by date, with full song lists.
- [ ] **A repeated identical request makes zero outbound calls** — verified by test.
- [ ] The rate limiter holds at 2/s under concurrent load, verified by test.
- [ ] The daily budget is enforced application-wide; exhaustion degrades to cache with a clear signal,
      never an error or an empty result.
- [ ] 429 responses trigger backoff and do not burn budget in a retry storm.
- [ ] A band with no setlist.fm presence returns a clean "no data" result distinguishable from an error.
- [ ] Budget consumption and cache hit rate are visible in the backoffice.
- [ ] `SETLISTFM_API_KEY` never appears in a log, a response, or the backoffice.

## Risks & open questions
- **The daily budget is the single biggest scaling constraint in the product.** Instrument it from day
  one; if the cache is not doing its job you need to know before users do, not after.
- Apply for the higher rate tier (16/s, 50k/day) now — it costs nothing to ask and changes the ceiling
  by 35×.
- Band identity is genuinely ambiguous (multiple bands share names; MBIDs are the only stable
  identity). Prefer MBID everywhere, and store the user's disambiguation choice so they are not asked
  twice.
- Decide the refresh policy for "has this band played since?" — a nightly job for bands with upcoming
  concerts is far cheaper than on-demand checks per user.
