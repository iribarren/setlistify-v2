# Backoffice Foundation

| | |
|---|---|
| **Spec ID** | `2026-08-21-backoffice-foundation` |
| **Backlog prompt** | `docs/prompts/08-backoffice-foundation.md` |
| **Command** | `/feature backoffice-foundation` |
| **Primary agents** | `backend-engineer` + `devops-security-engineer` (one branch, one PR) |
| **Branch** | `feature/backoffice-foundation` |
| **Depends on** | `04` — auth and accounts (merged) · `05` — concert domain API (merged, `4f28012`) |
| **Status** | **Draft — awaiting approval** |

---

## Overview

The application now holds data worth looking at. `User`, `Concert`, `Band`, `ConcertBand` and
`Venue` exist and are being written to by real flows, and the only way to see any of it is a
`psql` session. That is fine for two entities and stops being fine immediately: from this feature
onward, `docs/architecture.md` §9 expects every subsequent feature to be **observable through the
backoffice**, not through a database client.

This feature builds that backoffice — and, more importantly, builds the account that reaches it.
The owner account can read every user's email, every concert they tracked and, later, every
provider they linked. It is the single most valuable credential in the product. So the security
work here is not a wrapper around the CRUD screens; it *is* the feature, and the CRUD screens are
the small part.

Three decisions from `docs/architecture.md` §9 are load-bearing and this spec reverses none of them:

1. **Server-rendered inside Symfony, never in the Expo client.** No admin code ships to a public
   client, no admin route enters the OpenAPI spec, and the admin firewall is free to use sessions
   and 2FA precisely because it is not the API. `CLAUDE.md`'s API Contract section already states
   the backoffice is not part of the contract; this feature must leave that true, verified by test
   (US-11).
2. **It edits behaviour, never credentials.** No secret value is rendered on any admin screen, not
   even masked. EasyAdmin's default is to expose every field of every entity, which is exactly how
   a password hash or a refresh token ends up on a page — so field lists are explicit allowlists,
   and a test renders the screens and asserts it (D-46, US-10).
3. **Every write is audited.** `AuditLogEntry` records actor, entity, field, old → new, timestamp
   and IP, and it is append-only.

There is a fourth property this spec adds, because the existing codebase makes it cheap: the
backoffice must not weaken any invariant the API established. `Concert` reads are gated by
`App\Security\ConcertOwnerExtension` so a cross-owner lookup 404s rather than 403s
(`docs/specs/2026-08-21-concert-domain-api.md` D-27, `CLAUDE.md`). The admin legitimately reads
across owners — that is its job — but it must do so through a *different, audited channel* rather
than by loosening the API's gate. D-47 makes that separation explicit and testable.

Much of the groundwork already exists and is deliberately reused rather than rebuilt:

| Already in place | Where | Reused for |
|---|---|---|
| `app:admin:create` — the only path to `ROLE_ADMIN` | `backend/src/Command/CreateAdminCommand.php` | Owner provisioning (US-2) |
| `NoPublicRolesInOpenApiTest` — fails the build if any public write schema grows a `roles` field | `backend/tests/Functional/Auth/` | US-3, extended, not duplicated |
| `User::$isActive`, already enforced at login | `src/Entity/User.php`, `src/State/Processor/LoginProcessor.php` | Suspend/unsuspend (US-7) — no new state |
| `RateLimiterGuard` + Redis-backed limiters, fail-closed | `src/Service/Security/` | Admin login throttling (US-4) |
| `ADMIN_PATH_PREFIX`, `ADMIN_TOTP_ISSUER`, `ADMIN_IP_ALLOWLIST` | `docs/env-vars.md`, `backend/.env.example` | Already declared — this feature makes them real |
| `symfony/twig-bundle` | `composer.json` | EasyAdmin's rendering layer |

This feature ships **no user-facing product functionality** and **no API change**. It ships an
operator's window onto the data, and the auth wall in front of it.

## Goals

| Goal | Success looks like |
|---|---|
| The data is inspectable without `psql` | Users, concerts and bands are browsable, sortable and searchable at `/admin`; a new operator can answer "how many concerts were added this week" in one click |
| The admin door is separate from the API door | A valid API JWT authenticates *nothing* at `/admin`; an admin session authenticates *nothing* at `/api` — proven by test in both directions |
| A password alone is never enough | Every path to the dashboard passes a TOTP second factor; an enrolled-but-unverified session can reach only the 2FA form |
| `ROLE_ADMIN` stays unreachable from outside | No public request in any shape produces it; the console command remains the only path — proven by test, not convention |
| Nothing secret is renderable | No hash, token, TOTP secret or backup code appears in any rendered admin response — proven by asserting against the rendered HTML |
| Every write leaves a trace that outlives its subject | Each admin write produces an `AuditLogEntry` with actor, before and after, that survives deletion of the user it describes without resurrecting their personal data |
| Personal data is minimized by default | Emails are masked in every list; the full value takes a deliberate, rate-limited, audited action |
| The API's invariants survive | No admin code path calls an API Platform state provider, no admin route enters the OpenAPI spec, and `ConcertOwnerExtension` is neither modified nor bypassed for API traffic |
| Brute force is expensive | Admin login is rate-limited per IP and per identifier and locks the account out after repeated failures |

## User Stories

### US-1 — Reach the backoffice as the owner

> As the **product owner**, I want to log in at `/admin` with my password and a code from my
> authenticator app, so that I can inspect the app's real data without a database client.

**Acceptance criteria**

- **AC-1.1** `GET /admin` while unauthenticated redirects to the admin login form (`/admin/login`),
  never to the API, and never returns any dashboard content.
- **AC-1.2** The admin firewall is session-based form login, declared **before** the `api` firewall
  in `security.yaml`, with pattern `^/admin` derived from `ADMIN_PATH_PREFIX` (D-48).
- **AC-1.3** `ROLE_ADMIN` is required for **every** route under the prefix, including the dashboard
  and the login-adjacent routes that do not need to be public, enforced by an `access_control` rule
  on the prefix — not by per-controller annotations that a new controller can forget.
- **AC-1.4** After a correct password, the session is in a *partially authenticated* state and can
  reach **only** the 2FA form. Any other admin URL redirects back to it (US-5).
- **AC-1.5** After a correct TOTP code, the dashboard renders.
- **AC-1.6** The admin session cookie is distinct from the API's refresh cookie in name and path
  (D-54): `Secure` (outside dev), `HttpOnly`, `SameSite=Lax`, scoped to the admin prefix, with a
  30-minute idle timeout and an 8-hour absolute lifetime.
- **AC-1.7** Logging out destroys the session and invalidates the cookie; the back button reaches
  no cached authenticated page (`Cache-Control: no-store` on all admin responses).

### US-2 — Provision the owner account from a shell only

> As the **product owner**, I want the admin account to be creatable only from a shell, so that no
> web request can ever mint one.

**Acceptance criteria**

- **AC-2.1** `bin/console app:admin:create <email> [<password>]` remains the only way to obtain
  `ROLE_ADMIN`. This feature does not add a second path, an invite flow, or a self-promotion screen.
- **AC-2.2** The command is extended to report whether the account still needs 2FA enrollment, and
  prints the enrollment URL — it never prints a TOTP secret or a backup code.
- **AC-2.3** A `ROLE_ADMIN` account with no TOTP secret can reach **only** the enrollment route
  (D-49). It is not a usable admin account until enrollment completes.
- **AC-2.4** There is no admin UI to create, promote or demote an account. Roles are not an editable
  field on any admin screen (this is also covered by the field allowlist, AC-10.2).

### US-3 — `ROLE_ADMIN` is unreachable from the public API

> As the **product owner**, I want it to be structurally impossible to obtain admin rights through
> the public API, so that the backoffice's security does not depend on remembering a validation rule.

**Acceptance criteria**

- **AC-3.1** The existing `NoPublicRolesInOpenApiTest` continues to pass unchanged: no public write
  operation's schema contains a `roles`, `isAdmin` or equivalent field.
- **AC-3.2** A test attempts to reach the admin dashboard with a **valid API JWT** in an
  `Authorization: Bearer` header and asserts it is refused (redirect to admin login or 403), never
  authenticated. The API's JWT firewall grants no admin access whatsoever.
- **AC-3.3** A test attempts to reach an authenticated API endpoint (`/api/me`) with a valid **admin
  session cookie** and no bearer token, and asserts a 401. The two firewalls do not cross-authorize.
- **AC-3.4** A test asserts that a user registered through `POST /api/users` has roles exactly
  `["ROLE_USER"]` and cannot reach `/admin`.
- **AC-3.5** A `ROLE_USER` account attempting an admin route is refused, and the refusal is logged
  at `warning` with the user id, the requested path and the client IP — a `ROLE_USER` hitting
  `/admin` is a signal, not noise.

### US-4 — Brute force against the admin door is expensive

> As the **product owner**, I want repeated failed admin logins to be throttled and then locked out,
> so that the highest-value credential in the product cannot be ground down.

**Acceptance criteria**

- **AC-4.1** Admin login consumes two Redis-backed limiters through the existing
  `RateLimiterGuard` (fail-closed, never fail-open): `admin_login_credentials` — 5 attempts per
  15 minutes per (IP + email) — and `admin_login_ip` — 20 per 15 minutes per IP.
- **AC-4.2** After **10** consecutive failed attempts against a single admin account, that account
  is locked for 15 minutes; the lock is recorded and the counter resets on a successful login.
- **AC-4.3** Failed TOTP submissions are limited independently — 5 per 15 minutes per session — so a
  correct password does not buy an unlimited code-guessing budget.
- **AC-4.4** Every failed admin login and every lockout is logged at `warning` with IP and
  timestamp. Successful logins are logged at `info`.
- **AC-4.5** Enumeration is explicitly **not** a concern here (there is exactly one admin account and
  the operator knows its address), so error messages may be honest about lockout. This is a
  deliberate departure from the API's uniform-401 posture (`docs/specs/2026-08-21-auth-and-accounts.md`
  US-9) and is recorded as D-50.
- **AC-4.6** When `ADMIN_IP_ALLOWLIST` is non-empty, a request from an IP outside the listed CIDR
  ranges is rejected before authentication runs, with a 404 (not a 403 — an outsider learns nothing
  about the prefix existing) and a `warning` log line (D-42).

### US-5 — Two-factor authentication is mandatory

> As the **product owner**, I want a second factor on the admin account, so that a leaked or
> phished password is not sufficient to read every user's data.

**Acceptance criteria**

- **AC-5.1** TOTP 2FA via `scheb/2fa-bundle` + `scheb/2fa-totp` is enabled on the admin firewall and
  on no other firewall.
- **AC-5.2** Enrollment shows the QR code and the secret **once**, at enrollment time only, using
  `ADMIN_TOTP_ISSUER` as the issuer label. The secret is never rendered again on any screen, in any
  list, in any detail view, or in any log line.
- **AC-5.3** The TOTP secret is stored encrypted at rest with a key from the secrets layer, never
  in plaintext in the database, and is excluded from every serialization group and every EasyAdmin
  field list.
- **AC-5.4** Enrollment issues **10 single-use backup codes** (`scheb/2fa-backup-code`), displayed
  exactly once, stored **hashed** with the same auto-hasher configuration used for passwords. Using
  a backup code consumes it, writes an `AuditLogEntry`, and logs at `warning`.
- **AC-5.5** A test asserts that a session which passed the password step but not the TOTP step
  receives a redirect to the 2FA form for the dashboard and for at least one CRUD route — password
  alone never reaches data.
- **AC-5.6** Regenerating backup codes and resetting the TOTP secret are console-only operations
  (`app:admin:2fa:reset`), not web actions — recovery from a lost device requires shell access, the
  same bar as provisioning (D-49).

### US-6 — Browse users, concerts and bands

> As the **product owner**, I want read-only lists of users, concerts and bands, so that I can
> answer questions about the real data without writing SQL.

**Acceptance criteria**

- **AC-6.1** **Users** list shows: id, masked email (US-9), registration date, email-verified state,
  active/suspended state, and **concert count**. It is sortable by registration date and concert
  count, and searchable by email (search matches the real value; results still render masked).
- **AC-6.2** The concert count is produced by a single aggregate query for the page, not an N+1 loop
  per row.
- **AC-6.3** A **linked-provider count** column is deliberately **not** included: `StreamingAccount`
  does not exist until prompt 10. The column is omitted rather than stubbed with a zero (D-55), and
  prompt 10's spec adds it.
- **AC-6.4** **Concerts** list shows: id, date, timezone, venue name, owner (masked email), lineup
  as an ordered band list with the headliner first (`ConcertBand.billingOrder` 0 = headliner), and
  created-at. Sortable by date and created-at; filterable by owner and by upcoming/past using the
  existing `pastAfter` column.
- **AC-6.5** **Bands** list shows: id, name, normalized name, `setlistfmMbid` (null until prompt 09),
  created-at, and the number of concerts the band appears in. Searchable by name and normalized name
  — the normalized column is what makes a dedup mistake visible, which is the reason to show it.
- **AC-6.6** All three lists are paginated (25 per page) and every one of them is **read-only**: no
  create, no edit, no delete action is registered on `Concert`, `Band`, `Venue` or `ConcertBand`.
- **AC-6.7** Detail views exist for user, concert and band, and are likewise read-only apart from the
  narrow actions in US-7 and US-9.
- **AC-6.8** Admin reads go through Doctrine repositories/EasyAdmin directly and **never** through an
  API Platform state provider or `ConcertOwnerExtension` (D-47). A test asserts the admin concert
  list shows concerts belonging to more than one owner, while the API still 404s cross-owner.

### US-7 — Suspend, unsuspend and delete a user

> As the **product owner**, I want to suspend an abusive account and to erase an account on request,
> so that I can respond to abuse and to a GDPR erasure request without a database client.

**Acceptance criteria**

- **AC-7.1** Suspend/unsuspend toggles the existing `User::$isActive` flag. No new state field is
  introduced — login already refuses inactive users (`LoginProcessor`), so suspension takes effect
  through the path that already exists (D-44).
- **AC-7.2** Suspending a user **also revokes all of that user's refresh tokens**, so an
  already-issued session cannot outlive the suspension. A test asserts a previously working refresh
  token fails after suspension.
- **AC-7.3** Both actions require a confirmation step and record an `AuditLogEntry` with the field
  (`isActive`), old value and new value.
- **AC-7.4** Delete performs a **hard delete** of the user and cascades to everything owned by them:
  concerts (and their `ConcertBand` rows), refresh tokens, password-reset tokens and email
  verification tokens. `Band` and `Venue` are **not** deleted — they are shared, not user-scoped
  (`CLAUDE.md` glossary) — and a test asserts a band survives the deletion of the last user who
  referenced it.
- **AC-7.5** Delete requires typing the user's id to confirm, and is irreversible; the confirmation
  screen says so.
- **AC-7.6** The deletion's `AuditLogEntry` is written in the same transaction as the delete and
  **survives it**: it holds no foreign key to `users` and no plaintext personal data (D-43).
- **AC-7.7** No other write action exists anywhere in the backoffice in this feature. Provider
  configuration is prompt 11; nothing here becomes a general-purpose data editor.

### US-8 — See operational counts at a glance

> As the **product owner**, I want a dashboard with the few numbers that tell me whether the app is
> being used, so that opening the backoffice is immediately worth it.

**Acceptance criteria**

- **AC-8.1** The dashboard shows: total users, total concerts, and concerts created in the last
  7 days.
- **AC-8.2** Each count is a single `COUNT` query, computed on request with no caching layer —
  three counts do not justify one (D-53).
- **AC-8.3** The dashboard links to the users, concerts, bands and audit-log sections.
- **AC-8.4** No other analytics are added: no charts, no retention, no funnels. Out of scope, and
  the dashboard is where that boundary gets tested first.

### US-9 — Personal data is minimized on screen

> As a **user of the app**, I want the operator not to see my email address by default, so that
> routine administration does not casually expose my personal data.

**Acceptance criteria**

- **AC-9.1** Emails render masked in every list and in every detail view by default — local part
  first character plus `***`, domain first character plus `***`, TLD intact
  (`a***@e***.com`) — implemented as one reusable field type used everywhere an email appears
  (D-51).
- **AC-9.2** Revealing the full address is an explicit per-row action, never a hover, a tooltip or a
  query parameter.
- **AC-9.3** Every reveal writes an `AuditLogEntry` with `action = reveal_email`, the subject user
  id, the actor and the IP.
- **AC-9.4** Reveal is rate-limited (30 per hour per admin session), so it cannot be used to
  enumerate the whole user table one click at a time.
- **AC-9.5** The revealed value is returned in that response only. It is not stored in the session,
  not cached, and the response carries `Cache-Control: no-store`.
- **AC-9.6** Search by email still works on the real value (AC-6.1); a search term is not itself
  logged as a reveal, but the search is logged at `info`.

### US-10 — No secret is renderable anywhere

> As the **product owner**, I want to be certain no credential material can appear on an admin
> screen, so that the backoffice cannot become the leak it is meant to prevent.

**Acceptance criteria**

- **AC-10.1** No password hash, JWT, refresh token, reset token, verification token, TOTP secret,
  backup code, or provider credential is rendered on any admin screen — not in full, not truncated,
  not masked.
- **AC-10.2** Every EasyAdmin CRUD controller declares an **explicit** field list. No controller
  returns EasyAdmin's inherited default field set, and `User::$roles` is not among any declared list
  (D-46).
- **AC-10.3** A test crawls every registered admin route (dashboard, each list, each detail, the
  audit log) as an authenticated admin and asserts the rendered HTML contains none of: a bcrypt/argon
  hash prefix (`$2y$`, `$argon2`), a JWT (`eyJ`), a base32 TOTP secret pattern, or the literal names
  of secret-bearing entity fields.
- **AC-10.4** A test asserts that adding a new EasyAdmin CRUD controller without an explicit
  `configureFields` fails — enforced by a base controller that declares `configureFields` abstract,
  so the default is unreachable rather than merely discouraged.
- **AC-10.5** No secret value is read from the database into an admin template under any code path;
  provider credentials in particular are out of scope entirely (prompt 11) and remain in the secrets
  layer per `CLAUDE.md`.

### US-11 — The backoffice stays outside the API contract

> As a **frontend engineer**, I want the backoffice to be invisible to the OpenAPI spec and the
> generated client, so that admin surface never leaks into the app bundle.

**Acceptance criteria**

- **AC-11.1** A test fetches the generated OpenAPI document and asserts **no** path begins with the
  admin prefix and no schema references an admin-only entity (`AuditLogEntry`).
- **AC-11.2** No admin class is an API Platform resource; `AuditLogEntry` carries no `#[ApiResource]`
  attribute.
- **AC-11.3** Regenerating `frontend/api/` from the spec produces no admin types — verified by the
  same assertion, since the client is generated from that document.
- **AC-11.4** No admin route is added to `config/routing.yaml` under `/api`.
- **AC-11.5** `nelmio_cors` is not configured to allow cross-origin requests to the admin prefix.

### US-12 — Every write leaves a durable trace

> As the **product owner**, I want an append-only record of every administrative write, so that any
> change to a user's state can be explained afterwards.

**Acceptance criteria**

- **AC-12.1** `AuditLogEntry` fields: `id`, `occurredAt` (immutable), `actorId` (plain integer, no
  foreign key), `actorLabel` (a non-reversible short digest, D-43), `action`, `subjectType`,
  `subjectId`, `field` (nullable), `oldValue`, `newValue`, `ipAddress`, `userAgent` (truncated).
- **AC-12.2** An entry is written for **every** admin write: suspend, unsuspend, delete, reveal
  email, 2FA enrollment, backup-code use, and every failed authorization attempt against an admin
  route.
- **AC-12.3** `oldValue`/`newValue` never contain personal data in plaintext. For a field classified
  as personal data, the audit stores a keyed digest, which proves a change occurred and allows
  correlation without resurrecting the value (D-43).
- **AC-12.4** The entity is **append-only**: a Doctrine event subscriber rejects any update or
  delete of an `AuditLogEntry`, and a test asserts both are refused.
- **AC-12.5** The audit log is browsable in the backoffice — read-only, newest first, filterable by
  action and by subject type, with no edit and no delete action registered.
- **AC-12.6** Audit writing is centralized in one `AuditLogger` service. No controller writes an
  entry by constructing the entity directly, so "did we audit this?" is answerable by looking at one
  class's callers.
- **AC-12.7** Audit entries survive deletion of the user they describe — asserted by a test that
  deletes a user and then reads the entry back.

### US-13 — The whole thing is green in CI

> As a **developer**, I want the backoffice's security properties asserted by tests, so that a later
> change cannot quietly undo them.

**Acceptance criteria**

- **AC-13.1** Functional tests cover, at minimum: unauthenticated access denied (AC-1.1),
  `ROLE_USER` denied and logged (AC-3.5), API JWT denied (AC-3.2), admin cookie denied on the API
  (AC-3.3), 2FA enforced (AC-5.5), no secret rendered (AC-10.3), audit entry written on each write
  (AC-12.2), audit append-only (AC-12.4), and no admin path in the OpenAPI spec (AC-11.1).
- **AC-13.2** Rate limiting and lockout are tested against the real Redis-backed limiter in the
  compose stack, not a mock — consistent with the auth spec's approach.
- **AC-13.3** PHPStan level 9 passes on all new code; no baseline entry is added for it.
- **AC-13.4** Tests use no live external API (`docs/specs/2026-08-21-monorepo-and-environments.md`,
  D-2). Nothing here needs one.

## Technical Approach

### Backend (`backend/`) — the only sub-project touched

| Area | Work |
|---|---|
| Dependencies | `easycorp/easyadmin-bundle` ^5.5, `scheb/2fa-bundle`, `scheb/2fa-totp`, `scheb/2fa-backup-code`. Resolve **before** writing code (R-1) |
| Security | Second firewall `admin` declared *before* `api` (D-48); `access_control` rule on the prefix; form login + `two_factor` listener; separate session config (D-54) |
| Entities | `AuditLogEntry` (new). `User` gains `totpSecret` (encrypted, nullable) and `backupCodes` (hashed, JSON) — both excluded from every serialization group and every admin field list |
| Admin | `App\Controller\Admin\` — `DashboardController`, `UserCrudController`, `ConcertCrudController`, `BandCrudController`, `AuditLogEntryCrudController`, all extending a project base controller that makes `configureFields` abstract (AC-10.4) |
| Services | `App\Service\Admin\AuditLogger` (the single write path), `EmailMasker`, `AdminIpAllowlistListener`, `UserEraser` (cascade + token revocation, transactional) |
| Console | Extend `app:admin:create` (AC-2.2); add `app:admin:2fa:reset` (AC-5.6) |
| Rate limiting | Three new limiters in `rate_limiter.yaml`, consumed through the existing `RateLimiterGuard` |
| Migration | One migration: `audit_log_entries` table, two nullable `users` columns |

### Frontend (`frontend/`)

**None.** No client change, no regenerated types, no new screen. If this branch touches
`frontend/`, something has gone wrong.

### Decisions

Numbered from **D-42**; `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9` belong to
the backend skeleton spec, `D-10`–`D-17` to the frontend skeleton, `D-18`–`D-23` to auth,
`D-24`–`D-31` to the concert domain and `D-32`–`D-41` to the concert tracker UI.

**D-42 — `/admin` keeps the default path and is IP-restricted in production.**
*This resolves the prompt's open question.* The prompt is right that path obscurity is not security,
so the answer is not to hide the prefix — `ADMIN_PATH_PREFIX` stays `/admin`. The question that
matters is whether the door is publicly routable at all. Decision: **the allowlist is enforced, in
the application, as defence in depth**, and populating it is a production deployment requirement.
`ADMIN_IP_ALLOWLIST` empty means unrestricted (correct for local dev and CI); non-empty means an
`AdminIpAllowlistListener` rejects non-matching sources with a **404** before the firewall runs, so
an outsider cannot even confirm the prefix exists. A startup check logs an `error` when the app runs
in `prod` with an empty allowlist — noisy on purpose. Enforcing in the app rather than only at the
edge means the guarantee survives a proxy misconfiguration, and the listener must read the client IP
from Symfony's trusted-proxy-aware `getClientIp()`, never a raw header.

**D-43 — Audit records store an actor reference and personal data digests, never plaintext or FKs.**
*This resolves the prompt's GDPR/erasure question.* Two constraints collide: audit entries must
survive the deletion of the user they describe, and erasure must actually erase. So `AuditLogEntry`
holds `actorId` as a **plain integer with no foreign key** (nothing cascades into it, nothing
resurrects), plus `actorLabel` — a keyed digest of the actor's email, truncated, which lets two
entries be correlated as "same actor" without storing an address. Any `oldValue`/`newValue` for a
field classified as personal data is stored the same way. A boolean flip (`isActive: true → false`)
is not personal data and is stored literally; an email change is stored as digests. The trade-off
accepted: an audit trail that cannot be read back as "who exactly" without a separate lookup that
may no longer resolve. That is the correct direction for a record whose subject may have exercised
their right to erasure.

**D-44 — Suspension reuses `User::$isActive`; no new state field.** The flag exists and
`LoginProcessor` already refuses inactive users, so suspension works through a path that is already
tested. Adding a parallel `suspendedAt` would create two sources of truth for "can this person log
in". The cost is that `isActive` now carries two meanings (never-activated vs. suspended); it does
not currently carry the first, so there is no ambiguity to resolve today. **Revoking refresh tokens
is part of the action, not an afterthought** (AC-7.2) — without it, suspension is cosmetic for up to
30 days.

**D-45 — Erasure is a hard delete with an explicit cascade, executed by one service.** Soft delete
would be simpler and is wrong for a GDPR request. `UserEraser` runs in a transaction: write the
audit entry, revoke and delete tokens, delete concerts and their lineup rows, delete the user. Bands
and venues survive (shared, not user-scoped). Cascades are declared explicitly at the ORM/DB level
rather than relying on Doctrine's in-memory cascade, so an orphan cannot survive a code path that
does not load the collection.

**D-46 — Field lists are allowlists, enforced structurally.** EasyAdmin's default is "expose
everything", which is the mechanism by which a hash reaches a page. A project base CRUD controller
declares `configureFields` **abstract**, so a controller that forgets it does not compile. AC-10.3's
rendered-output assertion is the second net, not the first.

**D-47 — The admin reads across owners through Doctrine, never by weakening the API's gate.**
`ConcertOwnerExtension` is not modified, not made role-aware, and not bypassed with a
`ROLE_ADMIN` branch. The admin is a separate channel: EasyAdmin queries Doctrine directly and every
one of its reads is an operator action inside an audited, 2FA-gated session. Putting an
"unless admin" clause into the extension would put the product's most sensitive bypass inside the
class that guards every user's data on the *public* API — one refactor away from a leak. Two
channels, one invariant each.

**D-48 — Firewall order is `dev` → `admin` → `api` → `main`, and the prefix is a build-time value.**
Firewall matching is first-match-wins, so `admin` must precede `api`; today `/admin` falls through to
`main` and 404s, which the existing `security.yaml` comment anticipates. Note the constraint that
Symfony compiles firewall patterns into the container: `ADMIN_PATH_PREFIX` is therefore a
**build-time** setting — changing it requires a cache clear/rebuild, not just an env change. That is
documented in `docs/env-vars.md` rather than worked around, because a runtime-resolvable pattern
would mean matching the admin firewall in a request listener, which is strictly worse.

**D-49 — 2FA enrollment is forced on first login and recovery is console-only.** An admin account
with no TOTP secret can reach only the enrollment route, so "provisioned" and "usable" are the same
moment and there is no window where a password alone works. Lost-device recovery is
`app:admin:2fa:reset` from a shell — the same bar as provisioning. A web-based recovery flow would
be a second path to full admin access, which is exactly what US-2 exists to prevent.

**D-50 — Admin login errors are honest; enumeration is not a threat here.** The API deliberately
returns an indistinguishable 401 for every failure mode (auth spec US-9). The admin firewall does
the opposite: there is exactly one account, its address is known to the only person who should be
logging in, and an operator locked out at 3am needs to know *why*. Lockout messages state the lockout
and its duration.

**D-51 — Masking is one field type, used everywhere an email is rendered.** A masking helper called
at each call site is a helper someone forgets. One `MaskedEmailField` is the only way an email
reaches a template, and the reveal action is the only exception — itself audited and rate-limited.

**D-52 — No API change, and a test that keeps it that way.** This feature adds no endpoint, changes
no schema and regenerates no client types. AC-11.1's assertion is what makes that a property rather
than a claim, and it will keep holding as prompt 11 adds provider configuration.

**D-53 — Dashboard counts are computed per request, uncached.** Three `COUNT` queries on tables of
this size cost less than a cache-invalidation story. Revisit when a count gets slow, not before.

**D-54 — The admin session is a separate cookie with a short idle timeout, stored in Redis.**
Distinct name and path from the API's refresh cookie (which is scoped to `/api`, auth spec D-18), so
neither can be sent where the other is expected. Redis-backed session storage rather than files, so
the backoffice survives a multi-instance PaaS deployment without sticky sessions. 30-minute idle /
8-hour absolute: this is a session that reads every user's data, and it should not sit open on a
laptop all week.

**D-55 — The linked-provider count is omitted, not stubbed.** `StreamingAccount` arrives with prompt
10. A column that always reads `0` teaches the operator to ignore it. Prompt 10's spec adds the
column when there is something to count.

### Suggested implementation order

1. Resolve dependencies (EasyAdmin 5.5, `scheb/2fa` on Symfony 8.1 / PHP 8.4). Stop and report if
   any is unavailable — R-1.
2. `AuditLogEntry` entity + `AuditLogger` service + append-only subscriber + migration, with tests.
   The audit trail exists before the first thing that writes to it.
3. `admin` firewall, session config, `access_control`, ordering. Tests for AC-1.1, AC-3.2, AC-3.3.
4. 2FA: entity fields, enrollment flow, backup codes, forced-enrollment gate. Tests for AC-5.5.
5. Rate limiting, lockout, IP allowlist listener, logging.
6. Base CRUD controller (abstract `configureFields`) + dashboard + read-only user/concert/band lists.
7. Email masking field + reveal action.
8. Suspend / unsuspend / delete (`UserEraser`), each wired to `AuditLogger`.
9. Audit-log read view.
10. The security assertion suite (AC-10.3, AC-11.1) and the documentation updates.
11. `devops-security-engineer` review of the full diff before the PR — this branch is the operator
    credential surface.

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **Provider configuration** (`ProviderSetting`, enable/disable, playback mode) | Prompt 11, deliberately separate so it gets its own spec and review — it alters the app's legal classification |
| **Playlist and generation-job views** | The entities do not exist yet; the prompts that create them add their views |
| **Setlist-cache health view** | Same — prompt 09 creates the cache |
| **Any analytics beyond three counts** | Charts, retention, funnels: no operational need at one user |
| **Multi-admin roles, permissions, invites** | There is one owner. Introducing a role hierarchy now is speculative structure around the product's most sensitive access decision |
| **A self-service account-deletion flow for users** | Real GDPR requirement, but it is a public API feature with its own confirmation and enumeration questions. This feature gives the operator a way to honour a request received out of band |
| **Editing user data** (email, password, verification state) from the admin | Not needed to operate the app, and every editable field is a field that can be rendered by mistake |
| **Impersonation / "log in as user"** | The single most dangerous admin feature there is. If it is ever needed, it needs its own spec and its own audit design |
| **Bulk actions and CSV export** | Both are bulk personal-data egress; US-9 exists to make that deliberate, and an export button undoes it |
| **A design pass on the admin UI** | EasyAdmin's default theme is used as-is. The design canvas (`docs/design/canvas/`) governs the product, not the backoffice |
| **Email alerts on admin login** | Reasonable hardening, needs a delivery decision; revisit after the first deploy |

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| Prompt 04 merged — `User`, roles, `app:admin:create`, `NoPublicRolesInOpenApiTest`, `isActive`, `RateLimiterGuard` | `backend-engineer` | **Met** |
| Prompt 05 merged — `Concert`, `Band`, `ConcertBand`, `Venue`, `ConcertOwnerExtension` | `backend-engineer` | **Met** (`4f28012`) |
| `ADMIN_PATH_PREFIX`, `ADMIN_TOTP_ISSUER`, `ADMIN_IP_ALLOWLIST` declared | Prompt 00 | **Met** — in `docs/env-vars.md` and `backend/.env.example`; this branch makes them functional and documents D-48's build-time caveat |
| `symfony/twig-bundle` installed | Prompt 01 | **Met** |
| Redis reachable (rate limiter + admin sessions) | Prompt 00 | **Met** (compose `redis`, healthchecked) |
| A key for TOTP-secret encryption at rest | **This branch** | **To add** — new env var, secret, added to `docs/env-vars.md` **and** `backend/.env.example` together |
| EasyAdmin ≥ 5.5 available for Symfony 8.1 / PHP 8.4 | Upstream | **To verify** — `docs/architecture.md` §1 states 5.5 is the first release supporting Symfony 8; confirm before step 2 (R-1) |
| `scheb/2fa-*` available for Symfony 8.1 | Upstream | **To verify** — R-1 |
| An authenticator app on the operator's device | Developer | **To confirm** — needed to exercise AC-5.5 manually |

**Depended on by**

- **Prompt 11 (backoffice provider configuration)** — extends this dashboard, this firewall and this
  audit trail; D-46's abstract `configureFields` and `AuditLogger` are the seams it plugs into.
- **Prompt 09 (setlist.fm integration)** — the cache-health view lands in this backoffice.
- **Prompts 14–19 (playlists)** — generation-job observability lands here rather than in logs.
- **Every later feature**, per `docs/architecture.md` §9: observable through the backoffice, not
  through `psql`.

**Assumptions** *(labelled as assumptions, not verified facts)*

- EasyAdmin 5.5 and `scheb/2fa` have releases compatible with Symfony 8.1 on PHP 8.4. If not, R-1.
- `scheb/2fa` composes with EasyAdmin's routing without a custom listener; the 2FA form is a plain
  Symfony route outside EasyAdmin's dashboard.
- EasyAdmin's default theme renders acceptably under FrankenPHP with no asset pipeline work beyond
  `assets:install`.
- Doctrine's `datetimetz_immutable` concert columns render sensibly in EasyAdmin's date filters; if
  not, a plain string display is acceptable (AC-6.4 asks for the timezone to be visible, not
  filterable by it).

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **EasyAdmin 5.5 or `scheb/2fa` is not actually installable on Symfony 8.1 / PHP 8.4** | High — blocks the entire feature | Resolve dependencies as step 1, before any code. If 2FA is unavailable, **stop and report** — shipping the backoffice without a second factor is not an acceptable interim, and hand-rolling TOTP is exactly the code that should not be bespoke. A stopgap of "no backoffice yet" is safer than a password-only one |
| R-2 | **A secret reaches a screen through a path nobody enumerated** — a relation rendered with `__toString`, an EasyAdmin autocomplete, a search index, an exception page | High — this feature exists to prevent exactly this | D-46's structural allowlist plus AC-10.3's rendered-HTML crawl. Also: `User::__toString` (if added) must return the masked email, never the raw one; `APP_DEBUG` must be false in any deployed environment |
| R-3 | **Firewall misordering silently grants or denies the wrong thing** — first-match-wins means an `^/api` pattern placed above `^/admin` swallows nothing today but a future pattern change could | High and quiet | AC-3.2 and AC-3.3 test both directions explicitly, so a reordering breaks the build rather than the product. Keep the ordering comment in `security.yaml` |
| R-4 | **The cascade in erasure leaves orphans or deletes too much** — a missed token table, or a shared `Band` removed with its last user | High both ways: incomplete erasure is a compliance failure, over-deletion destroys other users' data | Explicit DB-level cascades (D-45), one transactional service, and tests asserting both that owned rows are gone and that a shared band survives. Re-check this list every time a new user-owned entity is added |
| R-5 | **Audit digests (D-43) make the trail unreadable when it is actually needed** — "who suspended this account" resolves to a digest of a deleted address | Medium | The actor is the owner and their id resolves for as long as their account exists; digests only bite for deleted subjects, which is the intended trade. If it proves too opaque in practice, the fix is a separate operator-identity table, not plaintext in the audit log |
| R-6 | **The operator locks themselves out** — lost phone, lost backup codes, and recovery is console-only by design (D-49) | Medium — recoverable with shell access, fatal without it | Backup codes are shown once and must be stored outside the app; `app:admin:2fa:reset` is documented in the README's operations section. Confirm shell access to production exists *before* enabling the allowlist and 2FA in production |
| R-7 | **`ADMIN_IP_ALLOWLIST` locks out a legitimate operator** — dynamic residential IP, travel, a proxy that rewrites the source | Medium | Empty means unrestricted, so the failure mode is opt-in. Client IP is read through Symfony's trusted-proxy handling. Document the recovery path (change the env var and redeploy) alongside the variable |
| R-8 | **Scope creep into "while we are here, make it editable"** — every list view invites one more editable field | Medium — each field is another render path to audit and another way to corrupt data | The Out of Scope table is binding. AC-6.6 and AC-7.7 make read-only a tested property, not an intention |
| R-9 | **N+1 queries in the users and bands lists** make the backoffice unusable as data grows | Low now, medium later | AC-6.2 requires aggregate counts; check the profiler on a seeded dataset before the PR |
| R-10 | **Admin session cookie interferes with the API's cookie** — same name, overlapping path, or a CORS config that starts sending credentials to `/admin` | Medium | D-54 gives distinct names and paths; AC-11.5 keeps CORS away from the prefix; AC-3.3 tests that the admin cookie authenticates nothing on the API |

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/env-vars.md`** *and* **`backend/.env.example`** — the new TOTP-encryption key variable, and
  a note on `ADMIN_PATH_PREFIX` being build-time (D-48) and on `ADMIN_IP_ALLOWLIST` semantics
  (empty = unrestricted, non-empty = enforced with a 404). Both files or neither.
- **`docs/architecture.md`** — record **D-42**–**D-55** in the Decisions section; update §9 to
  reflect what actually shipped (read views: users, concerts, bands — *not yet* playlists, jobs or
  cache health) and §11 (security posture) with the second firewall, the session model and the audit
  trail.
- **Root `README.md`** — the backoffice URL, the first-run sequence (`app:admin:create` →
  enroll 2FA), and `app:admin:2fa:reset` as the documented recovery path.
- **`CLAUDE.md`** — add a Setlistify-specific rule if the two-channel separation (D-47: the admin
  reads across owners through Doctrine, and `ConcertOwnerExtension` never learns about roles) is
  judged to belong alongside the existing 404-not-403 rule. Recommended: yes, as a short addendum to
  that rule.
- **`frontend/README.md`** — no change. The frontend is untouched.
- **No endpoint list in any README** — and specifically, **no mention of admin routes in the OpenAPI
  spec** (AC-11.1).

---

**Review requested.** This spec proposes decisions **D-42**–**D-55** and is not implementable until
approved. The two most consequential choices, and the two most worth disagreeing with, are **D-42**
(the allowlist is enforced in-app and required in production) and **D-43** (audit records store
digests rather than plaintext personal data, at the cost of readability). **D-49**'s console-only 2FA
recovery is the third: it is deliberately unforgiving, and R-6 is the price.
