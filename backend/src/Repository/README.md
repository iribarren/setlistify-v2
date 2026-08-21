# `Repository/`

> Query objects. All DB access goes through these.

**Rule:** only `Repository/` touches Doctrine's query layer. No other layer (controller, API
resource, service) builds a `QueryBuilder` or calls the `EntityManager` directly.

This directory is intentionally empty — this feature ships no domain entity to have a repository
for (D-4). It is filled alongside `Entity/` from prompt 05 onward.

See `docs/architecture.md` §3.
