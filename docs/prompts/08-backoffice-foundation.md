# 08 — Backoffice foundation

**Command:** `/feature backoffice-foundation` · **Agent:** `backend-engineer` + `devops-security-engineer` · **Depends on:** 04, 05

## Goal
An owner-only administrative interface at `/admin` where the app's real data — users, concerts,
bands — can be inspected without opening a database client, protected by a firewall separate from the
public API and gated behind two-factor authentication.

## Context
There is now real data worth looking at. From this prompt onward, every feature should be observable
through the backoffice rather than through `psql`.

Read `docs/architecture.md` §9 in full. Three decisions there are load-bearing and must not be
quietly reversed:

1. **The backoffice is server-rendered inside Symfony, not built into the Expo client.** No admin code
   ships to public clients, no admin route enters the OpenAPI spec, and the admin firewall gets to use
   sessions and 2FA instead of the API's JWTs.
2. **It edits behaviour, never credentials.** No secret value is rendered in any admin screen, even
   masked.
3. **Every write is audited.**

This account can read every user's personal data. Treat its security as seriously as the API's.

## Scope
- EasyAdmin 5.5 (the first release supporting Symfony 8) mounted at `ADMIN_PATH_PREFIX`, default
  `/admin`.
- **A separate `admin` firewall**: session-based form login, `ROLE_ADMIN` required for every route
  including the dashboard. The API's JWT firewall grants no admin access whatsoever.
- **TOTP two-factor authentication** (`scheb/2fa`) required for the admin firewall, with single-use
  backup codes.
- **Owner account provisioning by console command only** (`app:admin:create`). Confirm by test that
  `ROLE_ADMIN` cannot be obtained through any public endpoint.
- Rate limiting and lockout on admin login; optional `ADMIN_IP_ALLOWLIST` enforcement.
- **Read-only views**: users (with registration date, concert count, linked-provider count), concerts
  (with owner, lineup, date), and bands.
- **Narrow write access**: suspend/unsuspend a user, and delete a user on request (GDPR erasure,
  cascading correctly). Nothing else is editable here.
- A dashboard with counts that matter operationally: users, concerts, concerts added in the last
  7 days.
- `AuditLogEntry` entity — actor, entity type and id, field, old → new value, timestamp, IP — written
  automatically on every admin write, and viewable (append-only) in the admin.
- **Personal-data minimization**: emails partially masked in list views, full value only behind an
  explicit action that is itself audited.
- Tests covering: unauthenticated access denied, `ROLE_USER` denied, 2FA enforced, audit entry written
  on every write, admin routes absent from the OpenAPI spec.

## Out of scope
- Provider configuration — prompt 11, deliberately separate so it gets its own spec and review.
- Playlist or generation-job views — added by the prompts that create those entities.
- Any analytics beyond simple counts.
- Multi-admin roles or permissions. There is one owner.

## Acceptance criteria
- [ ] `/admin` is unreachable without authentication, and unreachable with a valid **API** JWT.
- [ ] A `ROLE_USER` account is refused, and the refusal is logged.
- [ ] 2FA is enforced: password alone never reaches the dashboard.
- [ ] **A test asserts `ROLE_ADMIN` cannot be granted through any public endpoint.**
- [ ] The owner account can only be created by console command.
- [ ] Users, concerts and bands are all browsable and searchable without a DB client.
- [ ] **No secret value appears in any admin screen** — verified by test against the rendered output.
- [ ] Every admin write produces an `AuditLogEntry` with actor, before and after values.
- [ ] Emails are masked in list views; unmasking is an explicit, audited action.
- [ ] **No `/admin` route appears in the OpenAPI spec** — verified by test.
- [ ] Admin login is rate-limited and locks out after repeated failures.

## Risks & open questions
- EasyAdmin will happily expose every field of every entity by default. Field lists must be explicit,
  never inherited — that default is exactly how a token or hash ends up on screen.
- Session-based admin auth alongside JWT API auth means two firewalls; check the `security.yaml`
  ordering carefully, since firewall matching is first-match-wins.
- GDPR erasure interacts with `AuditLogEntry`: audit records must survive user deletion, so store the
  actor reference in a way that does not resurrect deleted personal data.
- Decide whether `/admin` is publicly routable in production or restricted by IP. Path obscurity is
  not security; the firewall and 2FA are.
