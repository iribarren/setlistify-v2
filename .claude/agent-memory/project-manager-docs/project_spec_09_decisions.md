---
name: spec-09-setlistfm-decisions
description: Decisions D-56..D-70 proposed by the 2026-08-22 setlistfm-integration spec — MBID identity, shared disambiguation, dual storage, one budget gate, nightly refresh
metadata:
  type: project
---

`docs/specs/2026-08-22-setlistfm-integration.md` (backlog prompt 09) proposes **D-56 through D-70**.
Status as written: **draft, awaiting user approval**.

- **D-56** — MBID is band identity everywhere once resolved; `normalizedName` (D-25) only dedups
  *before* setlist.fm is consulted. Partial unique index on `bands.setlistfm_mbid`.
- **D-57** — Disambiguation choice stored on the **shared** `Band`, not per user. First resolver
  wins; wrong choices are corrected by the operator, audited. (R-4 is the accepted cost.)
- **D-58** — `SetlistGateway` is the only door; `SetlistFmClient` is private and a container test
  asserts nothing outside `Service/Setlist/` depends on it — same structural move as D-46.
- **D-59** — Two freshness classes, not one TTL: immutable data (`staleAfter = NULL`) never
  re-fetched; volatile data (search, first index page) carries `staleAfter`.
- **D-60** — Setlists stored **twice**: verbatim JSONB receipt + relational `Setlist`/`Song` rows, so
  a normalizer change is a re-derivation, never a re-fetch.
- **D-61** — One `SetlistFmBudget` gate (token bucket + daily counter + breaker) in Redis,
  application-wide, **fail-closed** like `RateLimiterGuard`.
- **D-62** — Web requests never queue on the rate limiter; bounded wait (`SETLISTFM_TOKEN_WAIT`)
  then degrade to cache. Only the nightly job may wait.
- **D-63** — Degradation is a field (`{source, fetchedAt, stale, reason}`), always HTTP 200 — never
  a status code, never an empty list.
- **D-64** — Redis-shared circuit breaker; retries spend real budget so they are capped/jittered.
- **D-65** — *Resolves the prompt's open question*: nightly, prioritized, budget-capped refresh job
  (25% share, bands with upcoming concerts or ended ≤7 days). On-demand freshness checks rejected.
- **D-66** — Setlist data is shared reference data, **not** user-scoped: the 404-not-403 gate (D-27)
  is neither applied nor weakened. Authenticated but not owner-filtered.
- **D-67** — Backoffice: read-only views + exactly two audited writes (fix MBID, clear band cache).
  No "refresh now" button — one click would be a budget spend.
- **D-68** — Cache/budget metrics in Redis day-keys, not a table. **D-69** — budget ceiling is env,
  never a constant, so a granted higher tier is config. **D-70** — fixtures recorded by hand once;
  one `@group live` smoke test, never in CI (D-2).

**Why:** Prompt 09 left the refresh policy open and flagged band ambiguity and the 1,440/day budget
as the product's biggest constraint; all three are resolved as named decisions.

**How to apply:** Highest D-number after this spec is **D-70** — continue from D-71. Two questions
are deliberately left open for the user: the 7-day/25% refresh defaults, and whether band search is
reachable outside concert creation. New env vars proposed: `SETLISTFM_BASE_URL`,
`SETLISTFM_HTTP_TIMEOUT`, `SETLISTFM_TOKEN_WAIT`, `SETLISTFM_REFRESH_BUDGET_SHARE`.
See [[backlog-prompt-to-spec-flow]], [[spec-05-concert-decisions]] and [[spec-08-backoffice-decisions]].
