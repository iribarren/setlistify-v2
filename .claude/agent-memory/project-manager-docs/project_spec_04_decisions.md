---
name: spec-04-auth-decisions
description: Decisions D-18..D-23 proposed by the 2026-08-21 auth-and-accounts spec — web token storage, email verification flag, Mailpit, custom refresh tokens, DTO-bound payloads
metadata:
  type: project
---

`docs/specs/2026-08-21-auth-and-accounts.md` (backlog prompt 04) proposes **D-18 through D-23**.
Status as written: **draft, awaiting user approval** — do not treat as settled until confirmed.

- **D-18** — Web: refresh token in an httpOnly `Secure` `SameSite=Strict` cookie scoped to the
  refresh path; access token in memory only. Native: `expo-secure-store`. Carries a **same-site
  deployment constraint** (web app and API must share a site) and one platform-branched storage
  adapter (a scoped exception to spec 03's AC-1.8 no-platform-fork rule).
- **D-19** — Email verification is built but **not mandatory at MVP**; enforced by
  `AUTH_REQUIRE_VERIFIED_EMAIL`, default false. Prompt 10 (provider linking) flagged as the natural
  place to turn it on.
- **D-20** — **Mailpit** compose service for dev; production mail is a `MAILER_DSN`, never a
  provider SDK. Test env uses the in-memory transport (consistent with [[spec-00-monorepo-decisions]] D-2).
- **D-21** — Custom `RefreshToken` entity + service instead of `gesdinet/jwt-refresh-token-bundle`
  (that bundle stores tokens in plaintext, no rotation, no reuse detection).
- **D-22** — `User` is never a writable API Platform resource; DTOs bound every public payload.
  This is what makes "`ROLE_ADMIN` unreachable" structural rather than validated.
- **D-23** — Enumeration resistance total on login and password reset, **deliberately partial on
  registration** (taken email → 422), traded for a usable signup and bounded by rate limits.

**Why:** Prompt 04 explicitly demanded that the web-storage, verification-mandatory and dev-email
questions be decided with written rationale rather than left open.

**How to apply:** Later specs touching sessions, mail or authorization cite these by ID. Highest
D-number after this spec is **D-23** — continue from D-24. See [[backlog-prompt-to-spec-flow]].
