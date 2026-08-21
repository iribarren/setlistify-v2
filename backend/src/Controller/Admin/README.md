# `Controller/Admin/`

> EasyAdmin CRUD controllers. Never in the public spec.

This directory exists as a placeholder only — the backoffice is out of scope for this feature
(prompt 08, `docs/specs/2026-08-21-backend-skeleton.md`). When it lands, this is where EasyAdmin's
CRUD controllers live, on a separate session-based, 2FA-gated firewall from the public API
(`docs/architecture.md` §9).

**Rule:** nothing in this directory, or its routes, may appear in the public OpenAPI document
(`CLAUDE.md`, API Contract — "the backoffice is not part of the contract").
