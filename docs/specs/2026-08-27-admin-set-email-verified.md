# FEATURE — Admin can set a user's email to verified

| | |
|---|---|
| **Spec ID** | `2026-08-27-admin-set-email-verified` |
| **Backlog prompt** | *(none — user-initiated request, outside the numbered backlog in `docs/prompts/`)* |
| **Command** | `/feature admin-set-email-verified` |
| **Primary agent** | `backend-engineer` (backend only — no frontend work, no API change) |
| **Type** | **FEATURE — implementation follows this document directly.** One branch `feature/admin-set-email-verified`, one PR |
| **Depends on** | `04` auth and accounts (merged — `User.emailVerifiedAt`, `EmailVerificationService`, `EmailVerifiedVoter`, D-18, D-19) · `08` backoffice foundation (merged — `UserCrudController`, `AuditLogger`, `AbstractAdminCrudController`, D-43, D-44, D-46, D-47, D-51, D-52) |
| **Implemented by** | *(this is the implementation)* |
| **Decisions** | **D-248** – **D-253** |
| **Status** | **Draft — review requested** |

---

## Overview

### What this feature is

A support capability, not a product feature. When a user cannot complete the normal email
verification flow — the verification mail bounced, landed in a corporate spam quarantine, the
24-hour token expired repeatedly, or the address is a distribution list that never delivers to a
human — there is currently no way out. The only path to `emailVerifiedAt` being set is a valid
token arriving back from the user's inbox.

This adds one narrow, audited, admin-only action to the existing EasyAdmin user screens: an
operator, inside the 2FA-gated `/admin` session, can mark a user's email as verified.
**Verify-only** — there is no admin-facing un-verify. Nothing else about verification changes.

### What the code looks like today

Verification is a nullable timestamp, not a boolean:

| Symbol | Today |
|---|---|
| `App\Entity\User::$emailVerifiedAt` | `?\DateTimeImmutable`, nullable column, `null` = unverified |
| `User::isEmailVerified()` | `null !== $this->emailVerifiedAt` — the boolean is derived, never stored |
| `User::markEmailVerified(\DateTimeImmutable $at)` | The only mutator, and the only one this feature needs |
| `App\Service\Security\EmailVerificationService` | Issues a hashed, single-use, 24-hour token; `confirm()` marks the token used and calls `markEmailVerified(now)` |
| `App\Security\Voter\EmailVerifiedVoter` | The `IS_EMAIL_VERIFIED` attribute, behind `AUTH_REQUIRE_VERIFIED_EMAIL` (**default `false`**); `LoginProcessor` is its only caller and denies with the same generic 401 as a wrong password |
| `App\Controller\Admin\UserCrudController` | Already renders `BooleanField::new('emailVerified')` — **read-only display**. Its three write actions are suspend/unsuspend, hard delete, reveal-email |
| `App\ApiResource\Me` / `UserRegistration` | Expose `emailVerified` as a **read-only** boolean. No API input anywhere writes it |

One consequence worth stating before the design: **today, flipping this flag changes nothing
observable for most deployments.** With `AUTH_REQUIRE_VERIFIED_EMAIL=false` (the default),
`EmailVerifiedVoter` returns `true` unconditionally, so an unverified user logs in fine. The flag is
a *latent* gate that this feature makes operable *before* the gate is switched on — which is the
right order, not a reason to defer.

### What this feature is not

It is not a redesign of the verification flow, not a "resend verification email" button, not an
un-verify tool, and not an API capability. The scope is one, one-directional action on one screen.

---

### Load-bearing rules this feature does not reverse

| `CLAUDE.md` rule | How this design honours it |
|---|---|
| *The backoffice is not part of the contract* | The action is an `#[AdminRoute]` POST on `UserCrudController`. No API Platform resource, operation, or DTO field is added or changed. `AdminOpenApiTest` already asserts no admin path appears in the OpenAPI document; it keeps passing unchanged (D-251) |
| *The backoffice edits behaviour, never credentials* | `emailVerifiedAt` is account state, not a secret. No provider credential, token, or secret is read, written, or rendered |
| *A user-scoped resource returns 404, never 403* | Untouched. `ConcertOwnerExtension` is not modified, not made role-aware, and gains no `ROLE_ADMIN` branch (D-47). This action reads and writes `User` through Doctrine inside the admin channel, exactly as suspend/unsuspend already does |
| *Data persistence and sensitive logic live in the backend* | Server-rendered Twig confirmation + POST. No client involvement of any kind |
| *Security is an MVP requirement* | CSRF-validated POST, confirmation step, audited through the single `AuditLogger` write path, inside the IP-allowlisted, 2FA-gated admin firewall |

### Existing groundwork this design builds on, not around

- `UserCrudController::confirmToggleActive()` / `performToggleActive()` is the exact shape to copy:
  a GET confirmation route that performs **no side effect**, then a POST that validates the
  `admin_user_action` CSRF token, mutates, flushes, audits, and redirects to the detail page.
  Re-rendering the confirmation template with an `error` and a `422` is the established failure
  response.
- `AbstractAdminCrudController::configureActions()` already disables `NEW`/`EDIT`/`DELETE`/
  `BATCH_DELETE`, and `configureFields()` is abstract (D-46), so nothing is exposed by accident.
- `App\Service\Admin\AuditLogger` is the single write path for `AuditLogEntry` (D-43, AC-12.6) —
  "did we audit this?" is answerable by listing that class's callers, and this feature must not
  break that property.
- `templates/admin/user/confirm_toggle_active.html.twig` is the template to model.
- `tests/Functional/Admin/AdminUserActionsTest.php` and `AdminWebTestCase` already cover the
  authenticated-admin, CSRF, and audit-assertion mechanics.

---

## Goals

1. An operator can resolve a stuck verification in the backoffice, with no console access and no
   SQL.
2. Every such flip leaves a durable, attributable record — who, when, from which IP.
3. The public API surface, the OpenAPI document, and the client are provably unchanged.
4. The normal token flow remains the *primary* path; this is the exception, and the code says so.

---

## Design decisions

### D-248 — Verify-only; no admin un-verify

The action is **one-directional**: an admin can move a user from unverified to verified. There is
no admin action to clear `emailVerifiedAt` again.

A two-way toggle was considered and rejected per explicit product direction: this ships as the
narrower, smaller-diff surface. The consequence is accepted — a mis-click on the wrong row (the
user list is searched on a *masked* email, D-51, so mis-selection is a realistic operator error) is
not correctable from the UI. If that turns out to matter in practice, un-verify is a small,
independent follow-up, not a reason to widen this branch now.

The button/action is labelled `Verify email` and is only rendered/enabled for a user whose
`emailVerifiedAt` is currently `null`; for an already-verified user the action does not appear
(no-op surface, not a disabled button with a confusing tooltip).

### D-249 — Audited through `AuditLogger`, with a literal timestamp, no digest

Every admin write in this codebase goes through `AuditLogger`, and `AuditLogAppendOnlySubscriber`
makes the resulting entries non-updatable and non-deletable. One entry per successful action:

| Field | Value |
|---|---|
| `action` | `verify_email_manually` |
| `subjectType` | `User` |
| `subjectId` | the user's id |
| `field` | `emailVerifiedAt` |
| `oldValue` | `null` |
| `newValue` | the new timestamp, ISO-8601 (`ATOM`) |

Values are stored **literally, not digested**. D-43 requires `AuditLogger::digest()` only for
values classified as personal data; a verification timestamp is not personal data any more than the
`isActive` boolean flip that D-43 explicitly allows to be stored literally. `actorLabel` remains a
digest of the admin's email, as always — so this feature writes no new plaintext personal data into
the audit log, and the record still survives the subject's GDPR erasure (AC-12.7).

The action name carries the "manually" qualifier so a future report can distinguish operator-set
verification from the token flow without joining anything.

### D-250 — The manual timestamp is *now*, never backdated

`markEmailVerified()` is called with the current time from `Psr\Clock\ClockInterface` (already a
dependency of `AuditLogger`; the controller injects it rather than calling `new
\DateTimeImmutable()` inline, so the action is testable with a frozen clock). The admin is offered
no date input.

Rationale: `emailVerifiedAt` answers *when did we come to trust this address*, and the honest
answer for a manual flip is "when the operator flipped it". A backdated value would forge a
verification event that never happened, and the audit entry would disagree with the column. There
is deliberately **no** separate `emailVerifiedByAdmin` column (D-253).

### D-251 — No API change, and the existing test proves it

No API Platform resource, operation, serialization group, or DTO field is added or modified.
`Me.emailVerified` and `UserRegistration.emailVerified` stay read-only projections of
`isEmailVerified()` and will simply report the new value. `AdminOpenApiTest` (which asserts no path
in the OpenAPI document starts with the admin prefix) is the standing guard and is **not** modified
by this branch; a modification to it during implementation is a review-blocking signal.

The frontend requires no change and no client regeneration.

### D-252 — The manual verify does not touch outstanding verification tokens, and `confirm()` stops overwriting

Outstanding `EmailVerificationToken` rows are left alone: not invalidated by a manual verify. A user
who later completes the normal token flow anyway must not have their timestamp silently overwritten
and disagree with the audit entry.

One small correction is in scope, because this feature creates the collision:
`EmailVerificationService::confirm()` currently calls `markEmailVerified($now)` unconditionally, so
a token consumed *after* a manual verification would silently overwrite the operator's timestamp.
`confirm()` becomes: consume the token, and call `markEmailVerified()` **only if the user is not
already verified**. Its return value then matches its existing docblock — *"true if a
previously-unverified user was just verified"* — which today it does not (it returns `true` for any
valid token). The endpoint's HTTP response is unchanged either way: a valid token still yields the
same success response, so this is invisible to the client.

### D-253 — No new column, no "verified by" provenance field

Provenance lives in the audit log, which is append-only, attributable, IP-stamped and already
queried through `AuditLogEntryCrudController`. A `verifiedByAdminId` column on `users` would
duplicate it, add a migration, add an FK that GDPR erasure of the *admin* would then have to
handle, and put admin identity onto a row that public read paths hydrate.

**This feature therefore ships zero schema changes and zero migrations.**

Rate limiting is also out of scope: flipping a flag on a row the admin can already read discloses
nothing new (unlike `reveal_email`, D-51, which is rate-limited because it discloses PII), and a
compromised admin session has strictly more damaging options available (suspend, hard delete). The
existing controls — IP allowlist, 2FA, session idle/absolute limits (D-54), CSRF, audit — are the
proportionate answer.

---

## User Stories

### US-1 — Verify a stuck user's email

> **As** the Setlistify operator,
> **I want** to mark a user's email address as verified from the backoffice,
> **so that** a user whose verification mail never arrived is not locked out of a feature gated on
> verification, without me touching the database.

**Acceptance criteria**

- **AC-1.1** — On both the user index and the user detail page in `/admin`, an action labelled
  `Verify email` is present for a user with `emailVerifiedAt === null`, and is not shown for a user
  who is already verified.
- **AC-1.2** — Activating it renders a confirmation screen naming the user by **masked** email
  (`MaskedEmailField`/`EmailMasker`, D-51 — never the plaintext address).
- **AC-1.3** — The confirmation screen performs **no** side effect: no mutation, no audit entry, no
  flush. Requesting it and navigating away leaves the user unchanged and the audit log unchanged.
- **AC-1.4** — Confirming issues a **POST**; a GET to the perform route is rejected by routing.
- **AC-1.5** — After a successful POST, `emailVerifiedAt` is non-null and equals the injected
  clock's current time, `isEmailVerified()` returns `true`, and the operator is redirected to the
  user's detail page where the `emailVerified` field renders as true.
- **AC-1.6** — `GET /api/me` for that user then reports `"emailVerified": true`, with no change to
  the response's shape or any other field.
- **AC-1.7** — Attempting to POST the perform route for a user who is already verified is rejected
  (422, no mutation, no new audit entry) — the action is not idempotent-by-silent-success, it refuses
  a no-op state transition explicitly.

### US-2 — Every verification flip is attributable

> **As** the Setlistify operator (and as whoever reviews the backoffice later),
> **I want** each manual verification recorded in the audit log,
> **so that** "who granted this account verified status, and when?" is answerable months later,
> including after the user has been erased.

**Acceptance criteria**

- **AC-2.1** — A successful verify writes exactly one `AuditLogEntry` with
  `action = 'verify_email_manually'`, `subjectType = 'User'`, `subjectId` = the user's id,
  `field = 'emailVerifiedAt'`, `oldValue = null`, and `newValue` = the new timestamp in `ATOM`
  format.
- **AC-2.2** — The entry is written through `App\Service\Admin\AuditLogger` — the controller never
  constructs `AuditLogEntry` directly (D-43, AC-12.6).
- **AC-2.3** — `actorLabel` is a digest, never the admin's plaintext email; the entry contains no
  field with the subject's email address.
- **AC-2.4** — The entry is visible in the existing audit log view in `/admin` with no change to
  `AuditLogEntryCrudController`.
- **AC-2.5** — A failed attempt (bad CSRF, or already-verified target) writes **no** audit entry
  and performs no mutation.

### US-3 — The capability cannot leak out of the backoffice

> **As** the developer maintaining the API contract,
> **I want** this capability to exist only in the server-rendered admin channel,
> **so that** no client, and no API consumer, can ever set its own verification state.

**Acceptance criteria**

- **AC-3.1** — The generated OpenAPI document contains no path for this action, and no operation
  anywhere accepts a writable `emailVerified`/`emailVerifiedAt` input. The existing
  `AdminOpenApiTest` and the existing registration-payload tests are unmodified by this branch and
  still pass.
- **AC-3.2** — Requesting either the confirm or the perform route without an authenticated admin
  session redirects to the admin login (or 404s where the IP allowlist applies), exactly as the
  suspend routes do — covered by extending the existing `AdminAccessControlTest` fixture list.
- **AC-3.3** — A POST to the perform route with a missing or invalid CSRF token re-renders the
  confirmation template with an error and HTTP **422**, and the user's `emailVerifiedAt` is
  unchanged.
- **AC-3.4** — `ConcertOwnerExtension` is unmodified by this branch (D-47).
- **AC-3.5** — `frontend/` has no diff in this branch.

---

## Technical Approach

### Files touched

| File | Change |
|---|---|
| `backend/src/Controller/Admin/UserCrudController.php` | Add the `verifyEmail` action to `configureActions()` (index + detail, rendered only for unverified users); add `confirmVerifyEmail()` (GET, no side effect) and `performVerifyEmail()` (POST, CSRF + guard-already-verified + mutate + flush + audit + redirect), modelled on the `toggleActive` pair. Inject `Psr\Clock\ClockInterface`. Optionally surface `DateTimeField::new('emailVerifiedAt')->onlyOnDetail()` so the detail page shows *when*, not just *whether* |
| `backend/templates/admin/user/confirm_verify_email.html.twig` | New, modelled on `confirm_toggle_active.html.twig`; the `admin_user_action` CSRF token |
| `backend/src/Service/Security/EmailVerificationService.php` | `confirm()` marks verified only when not already verified (D-252) |
| `backend/tests/Functional/Admin/AdminUserActionsTest.php` | New cases per US-1..US-3 |
| `backend/tests/Functional/Auth/EmailVerificationTest.php` | One case: consuming a valid token for an already-verified user consumes the token, returns the same success response, and leaves the existing timestamp intact |

Reused unchanged: `AuditLogger`, `AuditLogEntry`, `AbstractAdminCrudController`, `EmailMasker`,
`AdminCacheControlSubscriber`, `AdminUser`, the admin firewall and IP allowlist, `User` (no new
mutator — `markEmailVerified()` already exists and is sufficient for verify-only).

### CSRF token id

Reuse the existing `admin_user_action` token id rather than minting a new one — it already scopes
"a write action on a user from the admin channel", and every user action in this controller shares
it.

### Order of work

1. `EmailVerificationService::confirm()` guard (D-252) + its regression test.
2. Controller action pair + template.
3. Functional tests (US-1..US-3).
4. Documentation (see below).

### No migration

`emailVerifiedAt` already exists and is already nullable, and `markEmailVerified()` already exists.
This feature is a new caller of an existing mutator, from a new admin route. A branch that produces
a migration or a new entity method has misread this spec.

---

## Out of Scope

| Not in this feature | Why |
|---|---|
| Any API endpoint or DTO field for setting verification | The whole point is that this is admin-only (D-251) |
| An admin un-verify action | D-248 — explicit product decision: verify-only for this branch |
| A "resend verification email" admin action | A separate, defensible feature — it sends mail to a user and needs its own rate limiting. Not needed to unblock a stuck user, which this action already does |
| Notifying the user by email that an admin verified them | No transactional-mail template exists for it; the audit log is the record. Revisit if a compliance need appears |
| Backdating the timestamp | D-250 |
| A `verifiedByAdmin` column or provenance field | D-253 |
| Bulk / batch verification | `BATCH_DELETE` and batch actions are disabled at the base controller (D-46); a bulk trust-granting operation is the last thing this backoffice should acquire |
| Rate limiting this action | D-253 |
| Turning `AUTH_REQUIRE_VERIFIED_EMAIL` on, or changing what verification gates | Independent config/product decision; this feature makes the flag *operable*, it does not enable it |
| Changing `EmailVerificationService`'s token TTL, hashing, or issuance | Untouched apart from the D-252 guard |
| Any frontend change | AC-3.5 |

---

## Dependencies

Everything required is already merged on `master`:

1. `User.emailVerifiedAt`, `markEmailVerified()`, `EmailVerificationService`, `EmailVerifiedVoter`
   (prompt 04, D-18–D-19).
2. `UserCrudController` with a working confirm→POST→audit action pattern, `AuditLogger`,
   `AuditLogAppendOnlySubscriber`, `MaskedEmailField`, `AdminWebTestCase` (prompt 08, D-43–D-55).
3. An admin account with 2FA enrolled (`app:admin:create`) to exercise the flow manually.

No external service, no new environment variable, no new secret, no provider credential, no
migration, no client regeneration.

---

## Risks

| # | Risk | Mitigation |
|---|---|---|
| R-1 | **Manual verification defeats the purpose of verification.** An operator can grant trust to an address nobody proved they own | Accepted and bounded: the action is audited with actor, timestamp and IP; the audit log is append-only; the admin channel is IP-allowlisted and 2FA-gated. A support tool that can fix a stuck account is worth more than a flag no human can ever repair |
| R-2 | **Wrong-row flip is not correctable from the UI.** The user list shows masked emails (D-51), and this feature is deliberately verify-only (D-248), so a mis-click has no admin-side undo | Confirmation screen names the masked email and the user id before the irreversible step; the audit trail makes the mistake traceable even though not self-service-reversible. If this proves painful in practice, un-verify is a small, separately-specified follow-up |
| R-3 | **Timestamp/audit divergence** if a stale token is consumed after a manual verify | Closed by D-252 — `confirm()` no longer overwrites an existing timestamp |
| R-4 | Action count on `UserCrudController` grows to four; the controller drifts toward a general user editor | The base controller keeps `EDIT` disabled and `configureFields()` abstract (D-46). Every action remains a named, confirmed, audited route — not a form |

---

## Test Plan

| Test | Asserts |
|---|---|
| `AdminUserActionsTest::testConfirmScreenHasNoSideEffect` | AC-1.3 — GET confirm leaves `emailVerifiedAt` and the audit log unchanged |
| `AdminUserActionsTest::testVerifyEmailSetsTimestampAndAudits` | AC-1.5, AC-2.1, AC-2.2, AC-2.3 — frozen clock, exact audit fields |
| `AdminUserActionsTest::testVerifyActionHiddenForAlreadyVerifiedUser` | AC-1.1 |
| `AdminUserActionsTest::testVerifyRejectsAlreadyVerifiedTarget` | AC-1.7, AC-2.5 — 422, no mutation, no audit entry |
| `AdminUserActionsTest::testVerifyEmailRejectsBadCsrf` | AC-3.3, AC-2.5 — 422, no mutation, no audit entry |
| `AdminAccessControlTest` (extended) | AC-3.2 — both new routes unreachable without an admin session |
| `AdminOpenApiTest` (unchanged, must pass) | AC-3.1 |
| `EmailVerificationTest::testTokenDoesNotOverwriteExistingVerification` | D-252 — token consumed, response unchanged, timestamp preserved |
| `MeTest` (extended or asserted inline) | AC-1.6 — `/api/me` reflects the new value with an unchanged shape |

---

## Documentation to update, in this branch

Per `CLAUDE.md`'s mandatory documentation checklist, only these apply:

- **`docs/architecture.md`** — the backoffice section currently enumerates the admin's write
  actions ("suspend/unsuspend, hard delete, reveal-email, the two setlist.fm band writes,
  `ProviderSetting`"). Add manual email verification to that list and to the audited-writes
  paragraph.
- **Nothing else.** No new endpoint (no OpenAPI change), no new env var (`docs/env-vars.md` and
  `.env.example` untouched), no external-API behaviour change (`docs/external-apis.md` untouched),
  no setup/port/service change (both `README.md`s untouched), no CSP or header change, no new
  sub-project.

---

## Review requested

Per explicit product direction, this is **verify-only** (D-248) — no admin un-verify ships in this
branch. The other consequential calls are **D-250/D-253** (timestamp is always *now*, provenance
lives only in the audit log, so zero schema change) and **D-252** (a small correction to
`EmailVerificationService::confirm()` so a late token cannot silently overwrite an operator's
action — the only change in this branch outside the admin channel).

Please review and approve, or push back on any of the above, before a
`feature/admin-set-email-verified` branch is cut.
