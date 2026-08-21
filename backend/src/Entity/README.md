# `Entity/`

> Doctrine entities — the domain, no framework logic.

This directory is intentionally empty. This feature (`docs/specs/2026-08-21-backend-skeleton.md`)
ships no domain — inventing a throwaway entity here would create code whose only purpose is
deletion, and would seed a migration for a table nobody wants (D-4). The first real entity
(`Concert`, `Band`, …) lands in prompt 05.

See `docs/architecture.md` §3 for the full layering rules and §10 for the data model sketch.
