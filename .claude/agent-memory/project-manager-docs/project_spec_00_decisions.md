---
name: spec-00-monorepo-decisions
description: Approved decisions from the 2026-08-21 monorepo-and-environments spec — FrankenPHP runtime, and no live external API calls in CI
metadata:
  type: project
---

The foundation spec `docs/specs/2026-08-21-monorepo-and-environments.md` (backlog prompt 00) proposes
two decisions that were **not** pre-settled in `docs/architecture.md`. The user **approved both on
2026-08-21**:

- **D-1: FrankenPHP** (single container, worker mode off at MVP) instead of nginx + PHP-FPM.
- **D-2: CI makes no live calls to setlist.fm / Spotify / YouTube** — fixtures only.

**Why:** D-1 because a single container maps onto the managed-PaaS production target and removes an
inter-container surface locally; the prompt explicitly required picking one and recording why. D-2
because setlist.fm's 1,440 requests/day budget is **application-wide**, so a per-push CI job could
exhaust production's allowance.

**How to apply:** Treat both as settled project decisions when writing later specs that touch the
container runtime or CI — but confirm they were actually recorded in `docs/architecture.md` by the
implementation branch before citing that file as the source. D-2 in particular constrains every
later integration spec (09-11, 18): no per-push CI job may call setlist.fm, Spotify or YouTube.
