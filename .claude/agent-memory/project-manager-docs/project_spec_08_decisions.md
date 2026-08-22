---
name: spec-08-backoffice-decisions
description: Decisions D-42..D-55 proposed by the 2026-08-21 backoffice-foundation spec — in-app IP allowlist, digest-only audit values, two-channel owner reads, console-only 2FA recovery
metadata:
  type: project
---

`docs/specs/2026-08-21-backoffice-foundation.md` (backlog prompt 08) proposes **D-42 through D-55**.
Status as written: **draft, awaiting user approval**.

- **D-42** — `/admin` keeps the default path (obscurity isn't security); `ADMIN_IP_ALLOWLIST` is
  enforced **in the application** (404, pre-firewall) and required in production. Answers the
  prompt's open question.
- **D-43** — `AuditLogEntry` stores `actorId` as a plain int (no FK) + keyed digests for any
  personal-data value, so records survive GDPR erasure without resurrecting data. Answers the
  prompt's erasure question. Cost: readability (R-5).
- **D-44** — Suspension reuses existing `User::$isActive` (already enforced in `LoginProcessor`);
  **must also revoke refresh tokens** or it is cosmetic for 30 days.
- **D-45** — Erasure is a hard delete, one transactional `UserEraser`, DB-level cascades; `Band`
  and `Venue` survive (shared, not user-scoped).
- **D-46** — Base CRUD controller declares `configureFields` **abstract**, so EasyAdmin's
  expose-everything default is unreachable, not merely discouraged.
- **D-47** — **Two channels, one invariant each**: the admin reads across owners via Doctrine;
  `ConcertOwnerExtension` is never made role-aware. Protects the 404-not-403 rule in `CLAUDE.md`.
- **D-48** — Firewall order `dev → admin → api → main`; `ADMIN_PATH_PREFIX` is a **build-time**
  value because Symfony compiles firewall patterns into the container.
- **D-49** — 2FA enrollment forced on first login; recovery is console-only (`app:admin:2fa:reset`).
- **D-50** — Admin login errors are **honest** about lockout — a deliberate departure from the API's
  uniform-401 posture, since there is exactly one admin account.
- **D-51** — One `MaskedEmailField`; reveal is an explicit, rate-limited, audited action.
- **D-52** — No API change; a test asserts no admin path in the OpenAPI spec.
- **D-53** — Dashboard counts uncached. **D-54** — Separate Redis-backed admin session cookie,
  30-min idle / 8-h absolute. **D-55** — linked-provider count **omitted** (no `StreamingAccount`
  until prompt 10), not stubbed with a zero.

**Why:** Prompt 08 left the production-routability question open and flagged the audit/erasure
collision; both are resolved as named decisions per the project's spec convention.

**How to apply:** Highest D-number after this spec is **D-55** — continue from D-56. Much of prompt
08's groundwork already shipped in prompt 04 (`app:admin:create`, `NoPublicRolesInOpenApiTest`,
`isActive`, `RateLimiterGuard`) and the `ADMIN_*` env vars are already declared — reuse, don't
rebuild. Prompt 11 (provider config) plugs into `AuditLogger` and the abstract `configureFields`
seam. See [[backlog-prompt-to-spec-flow]] and [[spec-07-tracker-ui-decisions]].
