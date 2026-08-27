---
name: spec-admin-set-email-verified-decisions
description: Decisions D-248..D-256 proposed by the 2026-08-27 admin-set-email-verified spec — two-way audited toggle, no schema change, un-verify does not revoke sessions
metadata:
  type: project
---

`docs/specs/2026-08-27-admin-set-email-verified.md` proposes **D-248 through D-256**.
Status as written: **draft, awaiting user approval**. First spec not driven by a numbered file in
`docs/prompts/` — a direct user request slotted between backlog prompts 20 and 21.

- **D-248** — Two-way toggle (verify *and* un-verify), one EasyAdmin action, two distinct audit
  action names. Verify-only rejected: irreversible trust-granting on a masked-email list.
- **D-249** — New `User::clearEmailVerified()`; no general `setEmailVerifiedAt(?…)` setter (invites
  backdating). `emailVerifiedAt` is a nullable timestamp, not a boolean — `isEmailVerified()` derives.
- **D-250** — Manual timestamp is always *now* from `ClockInterface`; no backdating UI.
- **D-251** — Audited via `AuditLogger` as `verify_email_manually` / `unverify_email`, values stored
  **literally** (a timestamp is not personal data under D-43, same as the `isActive` flip).
- **D-252** — No API/OpenAPI change; existing `AdminOpenApiTest` unmodified is the guard.
- **D-253** — Un-verify does **not** revoke refresh tokens (unlike suspend, D-44). Suspend stays the
  lockout tool; confirmation copy says so.
- **D-254** — `EmailVerificationService::confirm()` gains an already-verified guard so a late token
  cannot overwrite the operator's timestamp (also fixes its return value vs. its docblock).
- **D-255** — No `verifiedByAdmin` column; provenance lives in the append-only audit log. **Zero
  migrations in this feature.**
- **D-256** — No rate limiting (unlike `reveal_email`, D-51): flipping a flag discloses nothing.

**Why:** the user asked for "admin can set an email to verified"; the design work was in deciding
reversibility, session impact, and whether provenance needed a column.

**How to apply:** Highest D-number after this spec is **D-256** — the next spec (backlog prompt 21,
social sharing) continues from **D-257**. Note `AUTH_REQUIRE_VERIFIED_EMAIL` defaults to `false`, so
verification currently gates nothing at runtime (`EmailVerifiedVoter`, only caller `LoginProcessor`)
— relevant to any future spec that assumes verification is enforced.
See [[spec-08-backoffice-decisions]] and [[spec-04-auth-decisions]].
