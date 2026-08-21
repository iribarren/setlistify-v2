# 04 — Authentication and accounts

**Command:** `/feature auth-and-accounts` · **Agent:** `backend-engineer` + `frontend-engineer` · **Depends on:** 01, 03

## Goal
A person can register, log in, stay logged in across app restarts, log out, and reset a forgotten
password — on all three platforms, with tokens handled the way `docs/architecture.md` §11 requires.

## Context
The backend and client both run but neither knows who anyone is. Every subsequent feature is scoped
to a user, so the `User` entity created here is the root of the whole data model.

One rule from `docs/architecture.md` §9 must be honoured from the first commit: **`ROLE_ADMIN` must
be unreachable through public registration.** Prompt 08 builds the backoffice on top of this
assumption, and it is far cheaper to enforce now than to audit later.

## Scope

**Backend**
- `User` entity: email (unique, citext or normalized), hashed password, roles, timestamps,
  verification state.
- Registration with strong validation; passwords hashed with Symfony's auto hasher.
- JWT access tokens (`lexik/jwt-authentication-bundle`) plus **refresh tokens with rotation** and
  reuse detection — a replayed refresh token invalidates the family.
- Logout (refresh-token revocation), password reset by emailed single-use time-limited token, and
  optional email verification.
- Rate limiting on login, registration and password reset (Symfony RateLimiter).
- `GET /api/me`.
- Role assignment is server-controlled. The registration path can only ever produce `ROLE_USER`.

**Frontend**
- Register, login, forgot-password and reset-password screens, built from prompt 02's components.
- Token storage: `expo-secure-store` on native, and a deliberate, documented choice on web.
- Automatic refresh on 401 with a single-flight guard, and redirect to login when refresh fails.
- Auth-aware routing: protected route groups, restored session on cold start.

## Out of scope
- Social login (Google/Apple). Note it for later; native platforms may eventually require Apple
  Sign-In if any social login exists.
- Admin authentication — prompt 08 uses a separate session-based firewall, not these JWTs.
- User profile editing beyond what login requires.

## Acceptance criteria
- [ ] Register → log out → log in → cold-start restore all work on web, iOS and Android.
- [ ] Refresh rotation works; a reused refresh token invalidates the family and forces re-login.
- [ ] **A test asserts that no public endpoint can grant `ROLE_ADMIN`.**
- [ ] Password reset works end to end; tokens are single-use and expire.
- [ ] Login, registration and reset are rate-limited, verified by test.
- [ ] Password hashes, tokens and reset tokens never appear in any response or log.
- [ ] Enumeration is not possible: a wrong password and an unknown email are indistinguishable.
- [ ] Tokens are in secure storage on native, never in plain `AsyncStorage`.

## Risks & open questions
- Web token storage is a real trade-off: `localStorage` is XSS-exposed, httpOnly cookies need CSRF
  handling and complicate the shared client. Decide explicitly and write the reasoning down.
- Email delivery needs a provider even in development — MailHog or similar in compose, a real
  provider in production.
- Decide whether email verification is mandatory before use. Mandatory is safer; it also adds
  friction to your own testing while the app is small.
