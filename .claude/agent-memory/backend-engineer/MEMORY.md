# Memory Index

- [Symfony skeleton internals](project_symfony_skeleton.md) — env handling, health-check pattern, RFC7807, CI wiring for backend/. Read before touching backend/.
- [Auth and accounts gotchas](project_auth_and_accounts.md) — JWT claims, refresh-cookie path, mailer double-events, rate-limiter config key, CI env vars. Read before touching login/refresh/logout/reset/verification.
- [Concert domain API gotchas](project_concert_domain_api.md) — custom DTO-resource PATCH/DELETE `read:false`, AP's `id`-key IRI quirk, debug:false stale container cache, phpdoc-parser for array-of-DTO. Read before adding another user-scoped DTO resource.
- [Backoffice foundation gotchas](project_backoffice_foundation.md) — scheb/2fa wiring traps, EasyAdmin raw-value leak paths, KernelBrowser kernel-reboot staleness, CSRF same-origin scheme. Read before touching /admin or Controller/Admin.
