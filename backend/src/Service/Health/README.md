# `Service/Health/`

> `HealthChecker` + one small check class per dependency (`DatabaseCheck`, `RedisCheck`).

Not one of `docs/architecture.md` §3's named `Service/` subdirectories — it exists because this
feature (`docs/specs/2026-08-21-backend-skeleton.md`) ships one real, working example across the
layers it legitimately touches (D-4, AC-5.3): `ApiResource/HealthStatus.php` (the public DTO) is
served by `HealthStateProvider` (API Platform state provider, no business logic), which delegates
to `HealthChecker` here to do the actual per-dependency round-trips.

Each check is a real round-trip (a trivial query against PostgreSQL, a `PING`-equivalent against
Redis), has its own short timeout, and never returns a credential, DSN, host, port or driver
exception message — status and a safe label only (AC-2.2, AC-2.4, AC-2.5).
