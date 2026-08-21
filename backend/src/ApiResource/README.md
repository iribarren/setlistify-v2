# `ApiResource/`

> API Platform resources + DTOs. The public contract.

**Rule:** controllers and API resources contain no business logic — they validate, delegate, and
shape a response. Every resource here is what generates `/api/docs` and the OpenAPI document that
`frontend/api/` is built from (`CLAUDE.md`, API Contract). If it isn't an API Platform resource, it
does not appear in the contract — see D-6 in
`docs/specs/2026-08-21-backend-skeleton.md`.

**Worked example:** `HealthStatus.php` (this feature) — a DTO backed by
`Service/Health/HealthStateProvider.php`, which delegates the actual dependency checks to
`Service/Health/`.
