# `Service/Setlist/`

> `SetlistFmClient`, `SetlistCache`, `SetlistRateLimiter`.

Out of scope for this feature (prompts 09–11, `docs/specs/2026-08-21-backend-skeleton.md`).

**Rule to remember when this fills in:** setlist.fm responses are always cached (`CLAUDE.md`). The
standard API key allows 2 requests/second and 1,440 requests/day **for the whole application**, not
per user — a cache miss here is a budget decision. See `docs/architecture.md` §5 for the three-tier
cache design.
