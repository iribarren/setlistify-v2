# `Repository/`

> Query objects. All DB access goes through these.

**Rule:** only `Repository/` touches Doctrine's query layer. No other layer (controller, API
resource, service) builds a `QueryBuilder` or calls the `EntityManager` directly.

Filled alongside `Entity/` starting with prompt 04 (`UserRepository`, token repositories) and
prompt 05 (`ConcertRepository`, `BandRepository`, `ConcertBandRepository`,
`docs/specs/2026-08-21-concert-domain-api.md`).

See `docs/architecture.md` §3.
