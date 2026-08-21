# Authentication and Accounts

| | |
|---|---|
| **Spec ID** | `2026-08-21-auth-and-accounts` |
| **Backlog prompt** | `docs/prompts/04-auth-and-accounts.md` |
| **Command** | `/feature auth-and-accounts` |
| **Primary agents** | `backend-engineer` + `frontend-engineer` (one branch, one PR) |
| **Branch** | `feature/auth-and-accounts` |
| **Depends on** | `01` — backend skeleton (merged) · `02` — design canvas (merged) · `03` — frontend skeleton (merged, `fa62299`) |
| **Status** | **Draft — awaiting approval** |

---

## Overview

The backend serves `GET /api/health` and the Expo client renders it. Neither knows who anyone is.
`backend/src/Entity/` contains no entity at all, and `frontend/lib/api/client.ts` carries an
intentionally empty middleware — the *auth header seam* left by prompt 03 — with a comment naming
this feature as its consumer.

This feature makes the application know its users. It creates the `User` entity, which is the root
of the entire data model sketched in `docs/architecture.md` §10: every `Concert`, every
`StreamingAccount`, every `Playlist` hangs off a user. Nothing after this prompt can be built
without it.

Three properties define the result:

1. **A session that survives.** Register, log in, close the app, reopen it, still be logged in —
   on web, iOS and Android — and log out deliberately when you want to. Access tokens are short and
   refreshed silently; the user never sees a token, an expiry, or an unexplained bounce to login.
2. **Tokens that cannot be quietly stolen or replayed.** Refresh tokens rotate on every use, are
   stored hashed, and belong to a *family*: presenting a token that has already been rotated is
   treated as theft and kills the whole family. Storage is `expo-secure-store` on native and — the
   decision this spec makes explicitly (D-18) — an httpOnly cookie on web. Never plain
   `AsyncStorage`, never `localStorage`.
3. **`ROLE_ADMIN` is structurally unreachable from the public API.** `docs/architecture.md` §9 says
   this "is a test, not a convention". Prompt 08 builds the entire backoffice on the assumption, so
   this spec makes role assignment impossible to reach rather than merely validated against: the
   registration input DTO has no `roles` field to attack, and a test asserts it.

The security posture is not aspirational here. `CLAUDE.md` states security is an MVP requirement,
not a stretch goal, and this is the feature where that is either true or not.

This feature ships **no product functionality** — no concerts, no playlists. It ships identity, and
the shape every later feature's authorization will follow.

## Goals

| Goal | Success looks like |
|---|---|
| An identity root exists | A `User` entity with a unique, normalized email, an auto-hashed password, server-controlled roles and a verification state; migration applied |
| A session outlives the process | Cold start on all three platforms restores the session without re-entering credentials, or lands cleanly on login if it cannot |
| Token theft is detected, not just prevented | A replayed refresh token invalidates its whole family and forces re-login — proven by a test, not by design intent |
| Admin is out of reach | No public request, in any shape, produces a user with `ROLE_ADMIN` — proven by a test |
| Accounts cannot be enumerated | A wrong password, an unknown email and an unverified account are externally indistinguishable on login; forgot-password always answers the same way |
| Nothing secret is ever emitted | No password hash, JWT, refresh token or reset token appears in any API response, log line, exception trace or profiler payload |
| Credential endpoints are expensive to attack | Login, registration, password reset and refresh are rate-limited by IP and by identifier, verified by test |
| The client has exactly one way to be authenticated | One session module owns storage, attachment, refresh and expiry; no screen touches a token directly |
| Auth is a routing concern, not a per-screen concern | Protected route groups redirect unauthenticated users; the auth group redirects authenticated ones |

## User Stories

### US-1 — Register an account

> As a **visitor**, I want to create an account with my email and a password, so that the concerts I
> track belong to me.

**Acceptance criteria**

- **AC-1.1** `POST /api/users` accepts `{ email, password }` and returns **201** with the created
  user's public representation (`id`, `email`, `emailVerified`, `createdAt`) — and nothing else.
- **AC-1.2** The request is bound to a dedicated input DTO exposing **only** `email` and `password`.
  There is no `roles`, `isVerified` or `id` field to send (D-22).
- **AC-1.3** Email is validated as an address, normalized (trimmed, lowercased) before persistence,
  and unique — enforced by a database unique constraint **and** a Doctrine validator, so the race
  between two simultaneous registrations ends in a 422, not a 500.
- **AC-1.4** Password policy: minimum 12 characters, maximum 4096 (bcrypt/argon input bound), and
  rejected if it appears in Symfony's `NotCompromisedPassword` check. The policy is stated in the
  422 response and rendered in the client before submission.
- **AC-1.5** Passwords are hashed with Symfony's **auto** hasher (`password_hashers: auto`); no
  algorithm is named in application code.
- **AC-1.6** The created user's roles are exactly `["ROLE_USER"]`, assigned server-side. See US-10.
- **AC-1.7** Registration with an already-registered email returns the same generic outcome shape as
  a successful registration would *not* — see AC-9.5 for the enumeration trade-off this spec accepts
  and why registration is treated differently from login and reset.
- **AC-1.8** A verification email is dispatched (US-7). Dispatch failure does **not** fail the
  registration; it is logged and the user can request a resend.

### US-2 — Log in

> As a **registered person**, I want to log in with my email and password, so that I can reach my
> own data.

**Acceptance criteria**

- **AC-2.1** `POST /api/login` accepts `{ email, password }` and, on success, returns **200** with a
  short-lived JWT access token in the body plus a refresh token delivered per platform (AC-4.6).
- **AC-2.2** Access-token TTL is **15 minutes** (`JWT_TTL`); refresh-token TTL is **30 days**
  (`REFRESH_TOKEN_TTL`). Both are already declared in `docs/env-vars.md`.
- **AC-2.3** The JWT payload carries `sub` (user id), `roles`, `iat`, `exp` and a `jti`. It carries
  no email, no name and nothing secret — a JWT is readable by anyone holding it.
- **AC-2.4** Wrong password, unknown email, unverified account and disabled account all produce an
  identical **401** with an identical RFC 7807 body and no timing signal wide enough to distinguish
  them (US-9).
- **AC-2.5** Login is rate-limited (US-9).
- **AC-2.6** On the client, a successful login persists the session (US-3), attaches the token at
  the existing seam in `frontend/lib/api/client.ts`, and routes into the protected group (US-8).
- **AC-2.7** The login screen renders the loading, error and disabled states from
  `docs/design/canvas/States.dc.html` and `Components.dc.html` — no bespoke spinner or error text.

### US-3 — Stay logged in across restarts

> As a **logged-in person**, I want the app to remember me when I reopen it, so that I am not asked
> for my password every time.

**Acceptance criteria**

- **AC-3.1** On cold start the app attempts session restore *before* rendering any authenticated
  screen, showing the canvas loading state — never a flash of the login screen followed by a jump.
- **AC-3.2** Restore succeeds when a valid refresh token is present: the app exchanges it for a new
  access token and lands on the authenticated home.
- **AC-3.3** Restore fails cleanly — no error dialog — when no token exists, the token is expired,
  or the refresh is rejected: the app lands on login with any local session state cleared.
- **AC-3.4** Native storage is `expo-secure-store`. A test and a code-level rule assert that no auth
  value is ever written to `AsyncStorage` or any unencrypted store.
- **AC-3.5** Web storage follows **D-18**: the refresh token lives in an httpOnly cookie the client
  cannot read, and the access token lives **in memory only**. No token is written to
  `localStorage`, `sessionStorage` or IndexedDB, asserted by test.
- **AC-3.6** Session restore is verified manually on all three platforms and the verified platforms
  are recorded in the PR (see R-6).

### US-4 — Silent refresh with rotation and reuse detection

> As a **logged-in person**, I want my session to renew itself in the background, so that a
> 15-minute token never interrupts what I am doing — and as the **product owner**, I want a stolen
> refresh token to be detected rather than used.

**Acceptance criteria**

- **AC-4.1** `POST /api/token/refresh` exchanges a valid refresh token for a **new access token and
  a new refresh token**; the presented refresh token is marked rotated and is never valid again.
- **AC-4.2** Refresh tokens are stored **hashed** (SHA-256 of a 256-bit random value); the plaintext
  exists only in the response/cookie. A database dump yields no usable refresh token.
- **AC-4.3** Each token records a `family` id, inherited across rotations from the token issued at
  login.
- **AC-4.4** **Reuse detection:** presenting a refresh token that has already been rotated (or one
  that was revoked) invalidates **every** token in its family, returns 401, and logs a security
  event with the user id and family id — never the token.
- **AC-4.5** The client refreshes on a 401 from any endpoint, with a **single-flight guard**:
  N concurrent 401s produce exactly **one** refresh request; all N requests are retried once after
  it resolves. Proven by a test issuing concurrent failing requests and asserting one refresh call.
- **AC-4.6** Transport is platform-specific and deliberate (D-18): on native the refresh token is a
  body value stored in `expo-secure-store`; on web it is set and read as an httpOnly, `Secure`,
  `SameSite=Strict` cookie scoped to the refresh path, and the client sends
  `credentials: 'include'`.
- **AC-4.7** A failed refresh clears the session and redirects to login exactly once — no retry
  loop, no repeated redirect.
- **AC-4.8** The refresh endpoint is rate-limited and does not distinguish "unknown token" from
  "expired token" in its response.

### US-5 — Log out

> As a **logged-in person**, I want to log out, so that my session cannot be used from this device
> afterwards.

**Acceptance criteria**

- **AC-5.1** `POST /api/logout` revokes the presented refresh token's **entire family** and returns
  **204**.
- **AC-5.2** On web the response clears the refresh cookie; on native the client deletes the token
  from secure store. In both cases the in-memory access token is discarded.
- **AC-5.3** After logout, the revoked refresh token returns 401 at `/api/token/refresh`.
- **AC-5.4** Logout succeeds (204, idempotent) even when the token is already invalid — logging out
  never fails visibly.
- **AC-5.5** The client routes to login and the protected route group is no longer reachable by
  back navigation or by typing a protected URL on web.

### US-6 — Reset a forgotten password

> As a **person who forgot their password**, I want to receive a reset link by email, so that I can
> regain access without contacting anyone.

**Acceptance criteria**

- **AC-6.1** `POST /api/password-reset/request` accepts `{ email }` and **always** returns **202**
  with the same body, whether or not the address exists (US-9).
- **AC-6.2** When the address exists, an email is sent containing a single-use token valid for
  **60 minutes**, stored hashed, bound to that user.
- **AC-6.3** `POST /api/password-reset/confirm` accepts `{ token, password }`, applies the same
  password policy as AC-1.4, and returns **204**.
- **AC-6.4** On success the token is consumed (a second use returns the same generic 400 as an
  invalid token), **all other outstanding reset tokens for that user are invalidated**, and **all
  refresh-token families for that user are revoked** — a password reset logs out every device.
- **AC-6.5** An expired, unknown or already-used token produces one indistinguishable 400.
- **AC-6.6** Requesting a reset never changes the account state — no lockout, no forced logout — so
  the endpoint cannot be used to grief a known user.
- **AC-6.7** Both the request and the confirm endpoints are rate-limited (US-9).
- **AC-6.8** The client ships **forgot-password** and **reset-password** screens; on web the reset
  link opens the reset screen via an Expo Router deep link carrying the token, and the same link
  opens the app on native (universal/app link) or falls back to the web screen.
- **AC-6.9** Resetting a password succeeds end to end against Mailpit in local development (D-20).

### US-7 — Verify an email address

> As the **product owner**, I want registered addresses to be provably real, so that password
> recovery and later provider linking are attached to an address the person controls.

**Acceptance criteria**

- **AC-7.1** Registration sends a verification email with a single-use token valid for **24 hours**,
  stored hashed.
- **AC-7.2** `GET|POST /api/email-verification/confirm` consumes the token and sets
  `emailVerifiedAt`; a used, expired or unknown token gives one indistinguishable response.
- **AC-7.3** `POST /api/email-verification/resend` is available to an authenticated, unverified user
  and is rate-limited; it always returns 202 and never reveals state.
- **AC-7.4** Per **D-19**, verification is **not** required to log in at MVP. The enforcement point
  exists in code as a security attribute (`IS_EMAIL_VERIFIED`) behind the
  `AUTH_REQUIRE_VERIFIED_EMAIL` flag, defaulting to `false` in dev/test and configurable in prod.
- **AC-7.5** With the flag enabled, login for an unverified user fails with the **same** generic 401
  as a wrong password (AC-2.4) — enabling the flag must not create an enumeration oracle.
- **AC-7.6** `GET /api/me` exposes `emailVerified` so the client can show a non-blocking banner
  prompting verification with a resend action.

### US-8 — Know who I am, and route accordingly

> As a **logged-in person**, I want the app to reflect my identity, so that screens can show my data
> and protected screens are not reachable when I am signed out.

**Acceptance criteria**

- **AC-8.1** `GET /api/me` returns **200** with `{ id, email, emailVerified, roles, createdAt }` for
  the authenticated user, and **401** otherwise.
- **AC-8.2** `GET /api/me` never returns a password hash, a token, or another user's data — asserted
  by test.
- **AC-8.3** Expo Router groups: `(auth)` holds login / register / forgot / reset; `(app)` holds
  everything requiring a session. Unauthenticated access to `(app)` redirects to login;
  authenticated access to `(auth)` redirects into `(app)`.
- **AC-8.4** The session lives in exactly **one** module (a React context over a small store, per
  D-12's "add client state when there is a reason"). No screen reads or writes a token directly;
  `grep` finds no token access outside that module and its storage adapter.
- **AC-8.5** The health screen from prompt 03 remains reachable and unauthenticated.
- **AC-8.6** The generated client (`frontend/api/`) is regenerated from the updated OpenAPI document
  and committed in this branch; the CI staleness check (D-10) passes.

### US-9 — Resist brute force and enumeration

> As the **product owner**, I want credential endpoints to be expensive to attack and silent about
> who exists, so that an account list cannot be harvested from the API.

**Acceptance criteria**

- **AC-9.1** Symfony RateLimiter policies, backed by Redis, applied as: login **5 per 15 min per
  (IP + email)** and **20 per 15 min per IP**; registration **5 per hour per IP**; password-reset
  request **3 per hour per email** and **10 per hour per IP**; verification resend **3 per hour per
  user**; refresh **60 per hour per IP**.
- **AC-9.2** Exceeding a limit returns **429** with a `Retry-After` header and an RFC 7807 body, and
  the client renders it as a specific, human message — not the generic error state.
- **AC-9.3** Each limit has a test that trips it.
- **AC-9.4** Login performs a dummy password verification when the email is unknown, so the response
  time does not separate "no such user" from "wrong password".
- **AC-9.5** Registration against an existing email returns **422** with a generic
  "this email cannot be used" message. **This is an accepted, documented enumeration trade-off**:
  hiding it entirely would require deferring all feedback to email and shipping a materially worse
  signup. It is bounded by AC-9.1's registration limit. Login and password reset — the endpoints an
  attacker would actually script — leak nothing.
- **AC-9.6** The rate limiter never becomes an availability hazard: if Redis is unavailable the
  limiter fails **closed** on credential endpoints (429), and this is asserted by test.

### US-10 — `ROLE_ADMIN` is unreachable from the public API

> As the **product owner**, I want privilege escalation to be structurally impossible, so that the
> backoffice built in prompt 08 rests on a guarantee rather than a habit.

**Acceptance criteria**

- **AC-10.1** `User::$roles` is never populated from request data. Registration sets
  `["ROLE_USER"]` in a service, not from input.
- **AC-10.2** No public API operation exposes `roles` as writable: the `User` entity is **not**
  exposed as a writable API Platform resource; registration uses an input DTO and `/api/me` uses an
  output DTO (D-22).
- **AC-10.3** **A test asserts that no public endpoint grants `ROLE_ADMIN`** — it enumerates the
  OpenAPI document's public write operations and fails if any accepts a `roles` property, and it
  attempts registration with `roles: ["ROLE_ADMIN"]` (top-level and nested) asserting the created
  user has exactly `["ROLE_USER"]`. This is the prompt's bolded criterion and the spec's single most
  important test.
- **AC-10.4** The only path to `ROLE_ADMIN` is a console command
  (`app:user:promote` / `app:admin:create`), documented in `docs/architecture.md` §9 and runnable
  only with shell access.
- **AC-10.5** The API JWT firewall grants no access to `/admin` — the admin firewall stays separate
  (prompt 08). A test asserts a valid API JWT does not authenticate an `/admin` request.

### US-11 — Secrets never leak into a response, a log or a trace

> As the **product owner**, I want credentials and tokens to be invisible outside their intended
> use, so that a log aggregator or an error report is not a credential store.

**Acceptance criteria**

- **AC-11.1** `User::$password` (the hash), all token entities and their plaintext values are
  excluded from every serialization group; a test serializes a user and asserts no hash appears.
- **AC-11.2** A Monolog processor redacts `password`, `token`, `refresh_token`, `authorization` and
  `set-cookie` from log records; a test asserts a failed login logs no password.
- **AC-11.3** Exception responses in `prod` never echo request bodies. `debug` output is off in
  `prod` and the profiler is not installed there.
- **AC-11.4** The client never logs a token on any platform (the rule prompt 03 wrote at
  `frontend/lib/api/client.ts:42`), asserted by a test that spies on `console.*` during a
  request/refresh cycle.
- **AC-11.5** JWT keys, `APP_SECRET` and mailer credentials come from env/secrets only; keys are
  gitignored and `.env.example` carries names and dummy values only.

### US-12 — The whole flow is tested and green in CI

> As a **developer**, I want auth covered by automated tests, so that a later feature cannot quietly
> break login.

**Acceptance criteria**

- **AC-12.1** Backend functional tests cover: register, login, `/api/me`, refresh rotation, reuse
  detection, logout, reset request/confirm, verification, every rate limit, AC-10.3, AC-11.1.
- **AC-12.2** Emails are asserted with Symfony's mailer test assertions against the in-memory
  transport; no test sends real mail (extends D-2's principle: CI reaches no external service).
- **AC-12.3** Frontend tests cover: login form validation and submission, single-flight refresh,
  session restore on cold start, redirect on refresh failure, protected-route redirect, and the
  no-plain-storage assertions (AC-3.4 / AC-3.5) — stubbing `global.fetch` per D-14.
- **AC-12.4** PHPStan level 9 with no baseline (D-8), ESLint and `tsc --noEmit` all pass.
- **AC-12.5** The CI pipeline is green, including the generated-client staleness check (D-10).

## Technical Approach

### Backend (`backend/`)

| Area | Shape |
|---|---|
| Entities | `User` (email `CITEXT` or normalized-lowercase + unique index, `password`, `roles` JSON, `emailVerifiedAt`, `createdAt`, `updatedAt`, `isActive`), `RefreshToken` (hash, `family`, `user`, `expiresAt`, `rotatedAt`, `revokedAt`), `PasswordResetToken`, `EmailVerificationToken` |
| API surface | `POST /api/users` (register), `POST /api/login`, `POST /api/token/refresh`, `POST /api/logout`, `GET /api/me`, `POST /api/password-reset/request`, `POST /api/password-reset/confirm`, `POST /api/email-verification/confirm`, `POST /api/email-verification/resend` — all as API Platform resources/operations so the OpenAPI document stays the single source of truth (`CLAUDE.md`) |
| Auth | `lexik/jwt-authentication-bundle` for access tokens; a custom `RefreshTokenService` for rotation, hashing, families and reuse detection (D-21) |
| Layering | Per `docs/architecture.md` §3 and the READMEs from prompt 01: HTTP/state-provider layer thin, all rules in `Service/Security/`, persistence in `Repository/` |
| Rate limiting | `symfony/rate-limiter` with the Redis storage already configured for the cache/Messenger transport |
| Mail | `symfony/mailer` + Twig email templates; `MAILER_DSN` only — no provider SDK in code (D-20) |
| Cleanup | A console command (`app:tokens:prune`) deletes expired refresh/reset/verification rows, scheduled in production |

### Frontend (`frontend/`)

| Area | Shape |
|---|---|
| Session module | `frontend/lib/auth/` — `SessionProvider` (context), `sessionStore` (in-memory access token + user), and a **storage adapter** with `.native.ts` / `.web.ts` implementations. The only place tokens are read or written (AC-8.4) |
| Client wiring | The empty `authHeaderSeam` middleware in `frontend/lib/api/client.ts` gains the `Authorization` header; a response middleware performs single-flight refresh on 401 |
| Routing | Expo Router groups `(auth)` and `(app)` with a redirect guard in each group's `_layout.tsx`; the root layout renders the canvas loading state while restore is in flight |
| Screens | `login`, `register`, `forgot-password`, `reset-password` built from prompt 02's `Button`, `TextInput`, `Card` and the shared state components — no new primitives unless the canvas already specifies them (D-16) |
| Types | Regenerated `frontend/api/` (`npm run generate:api`); zero hand-written request/response types |

### Decisions

Numbered from **D-18**; `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9` belong to
the backend skeleton spec and `D-10`–`D-17` to the frontend skeleton spec.

**D-18 — Web token storage: refresh token in an httpOnly cookie, access token in memory only.**
*This resolves the prompt's first open question.* The three candidates:

| Option | XSS exposure | Cost |
|---|---|---|
| `localStorage` | Any XSS exfiltrates a 30-day refresh token silently | None |
| In-memory only | None, but nothing survives a reload — no cold-start restore on web (AC-3.2 fails) | None |
| **httpOnly cookie for refresh + memory for access** | XSS can *use* the session while the page is open but cannot **exfiltrate** the long-lived token | CORS credentials, a same-site deployment constraint, one platform branch in the storage adapter |

We take the third. The asymmetry is what decides it: a stolen refresh token is a 30-day
account takeover from anywhere, while an in-page XSS is already game over *for that session* under
every option. Making the long-lived credential unreadable by JavaScript is therefore the only choice
that changes an outcome. CSRF is contained rather than eliminated: the cookie is `Secure`,
`SameSite=Strict`, and scoped to the refresh path, so it is the *only* cookie-authenticated endpoint
in the whole API — every other endpoint stays pure bearer-token and is structurally CSRF-immune. A
forged cross-site POST to the refresh endpoint would set a new cookie in the victim's own browser
and return a body the attacker's origin cannot read; if that residual is later judged unacceptable,
a double-submit token can be added to that single endpoint without touching anything else.

Two costs are accepted and must be honoured:
- **Deployment constraint:** the web app and the API must be served from the same site (e.g.
  `app.setlistify.app` and `api.setlistify.app`) so `SameSite=Strict` permits the cookie. Local dev
  already satisfies this (`localhost:8081` and `localhost:8000` differ by port only, and SameSite is
  site-scoped, not origin-scoped) but CORS must allow the exact origin with
  `Access-Control-Allow-Credentials: true` — never `*` (`docs/env-vars.md`). Recorded as R-1.
- **A platform branch**, at the storage-adapter layer only (`storage.web.ts` / `storage.native.ts`).
  Prompt 03's AC-1.8 forbids platform forks of the UI; this is not one — no screen, component or
  hook branches, and the adapter has a single narrow interface. The exception is recorded in
  `frontend/README.md`.

**D-19 — Email verification is shipped, enforced by a flag, and off by default at MVP.**
*This resolves the prompt's third open question.* Mandatory verification is safer but, as the prompt
itself notes, adds real friction while the product is being built and self-tested; making it
optional forever means retrofitting an enforcement point into every entry path later. So: build the
whole flow now (token, email, confirm, resend, `emailVerifiedAt`), enforce it through a single
`IS_EMAIL_VERIFIED` security attribute governed by `AUTH_REQUIRE_VERIFIED_EMAIL`, default `false`.
Flipping it to mandatory later is a config change and a test, with no migration and no new code path
— and AC-7.5 guarantees the flip does not open an enumeration oracle. Unverified users see a
non-blocking banner. **Prompt 10 (provider account linking) should reconsider this**: linking a
Spotify account to an unverified address is a materially higher-stakes action than tracking a
concert, and gating *that* on verification is a good candidate for the first place the flag turns on.

**D-20 — Mailpit in compose for development; a DSN-only mailer everywhere.**
*This resolves the prompt's second open question.* A `mailpit` service joins `compose.yaml` (SMTP on
1025, web UI on 8025) and `MAILER_DSN=smtp://mailpit:1025` goes into `backend/.env.example`.
Mailpit rather than MailHog: same role, actively maintained, better UI. Application code depends on
`symfony/mailer` and the DSN only — no provider SDK, no vendor lock, and choosing a production
provider stays a deployment decision (a secret in the PaaS store), not a code change. The `test`
environment uses the in-memory transport so AC-12.2's assertions run with no service at all, in
keeping with D-2: CI reaches no external network.

**D-21 — A custom refresh-token implementation, not `gesdinet/jwt-refresh-token-bundle`.**
The bundle stores refresh tokens **in plaintext** and implements neither rotation nor reuse
detection — the two properties AC-4.1/AC-4.4 exist for. Adapting it would mean overriding its entity,
its repository and its handler, which is more code than the ~150 lines this needs, in a shape
constrained by someone else's model. We own `RefreshToken` and `RefreshTokenService` directly:
hashed at rest, family-scoped, rotation and reuse detection as first-class behaviour.

**D-22 — The `User` entity is never a writable API resource; DTOs bound every public payload.**
Registration binds a `RegisterUserInput` DTO (`email`, `password` — nothing else), and `/api/me`
returns a `UserOutput` DTO. This is what makes AC-10.3 structural rather than defensive: there is no
`roles` field to filter, deny or forget to deny, and adding a sensitive field to `User` later cannot
accidentally expose it. The cost is two small DTOs and two state processors/providers; it is the
cheapest possible insurance on the guarantee prompt 08 depends on.

**D-23 — Enumeration resistance is total on login and reset, and deliberately partial on
registration.** Login (AC-2.4) and password reset (AC-6.1) leak nothing — those are the endpoints an
attacker scripts. Registration returns a distinguishable 422 on a taken email (AC-9.5), because the
alternative — accepting every signup and deferring all feedback to email — degrades the primary
conversion path of the product to close a low-value oracle that is already rate-limited to 5/hour/IP.
The trade-off is recorded here so it reads as a decision, not an oversight.

### Suggested implementation order

1. `User` entity + migration + `app:admin:create` console command. Write **AC-10.3's test first** —
   it should pass trivially and keep passing forever.
2. Registration (DTO, processor, validation, hashing) and `GET /api/me`.
3. LexikJWT setup, login, and the JWT firewall.
4. `RefreshToken` + rotation, families, reuse detection, `/api/token/refresh`, `/api/logout`.
5. Mailpit in compose; verification and password-reset flows.
6. Rate limiters and the enumeration/leak hardening (US-9, US-11) with their tests.
7. Regenerate `frontend/api/`; build the session module and storage adapters.
8. Auth screens, route groups, restore-on-cold-start, single-flight refresh.
9. Frontend tests; manual verification on web, iOS and Android; documentation sweep (`/doc-check`).

## Out of Scope

| Not in this feature | Why / where it goes |
|---|---|
| **Social login (Google / Apple)** | Deferred entirely. Note for whoever revisits it: **if any social login ships on iOS, App Store review requires Sign in with Apple alongside it** — so it is one decision, not several, and belongs in its own spec |
| **Admin authentication and the backoffice** | Prompt 08. It uses a separate session-based firewall with TOTP (`docs/architecture.md` §9); these JWTs grant no admin access (AC-10.5) |
| **Profile editing** (name, avatar, email change, password change while logged in) | Nothing here needs it. Email change in particular re-opens verification and enumeration questions and deserves its own spec |
| **Account deletion / suspension** | Prompt 08's narrow admin write surface; a self-service GDPR flow is a later spec |
| **Multi-factor authentication for regular users** | Admin 2FA is prompt 08. User-facing MFA has no demand signal yet |
| **Per-resource authorization (voters for concerts, playlists)** | Arrives with the resources themselves, prompt 05 onward. This spec provides the identity those voters will read |
| **Session/device management UI** ("log out everywhere", listing active sessions) | The data model supports it (families are per-login); no UI now |
| **A chosen production email provider** | D-20 keeps it a DSN. Selecting one is a deployment task |
| **CAPTCHA / bot defence beyond rate limiting** | Revisit if abuse appears |
| **SEO / server rendering of auth pages** | D-17 stands: the web build is a SPA |

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| Prompt 01 merged — Symfony/API Platform skeleton, layer directories, PHPStan L9, backend CI | `backend-engineer` | **Met** |
| Prompt 02 merged — design canvas (form fields, buttons, error/loading states) | design canvas | **Met** |
| Prompt 03 merged — Expo app, theme, components, generated client, the auth-header seam | `frontend-engineer` | **Met** (`fa62299`) |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, `JWT_TTL`, `REFRESH_TOKEN_TTL` declared | Prompt 00 | **Met** — already in `docs/env-vars.md`; a keypair must be generated locally and gitignored |
| `MAILER_DSN` and `AUTH_REQUIRE_VERIFIED_EMAIL` | **This branch** | **To add** to `docs/env-vars.md` **and** `backend/.env.example` together |
| Redis reachable for the rate limiter | Prompt 00 | **Met** (compose `redis`, healthchecked) |
| `mailpit` service in `compose.yaml` | **This branch** | **To add** (D-20) |
| PostgreSQL `citext` extension available, or normalization chosen instead | Prompt 00 | **To verify** — `postgres:17.6-alpine` ships contrib; if enabling it complicates the migration, normalize-on-write plus a unique index is the fallback |
| `expo-secure-store` supported on the pinned Expo SDK and passing D-15's web-support gate | `frontend-engineer` | **To verify** — it is an Expo-first module and is a **no-op on web**, which is precisely why D-18 exists |
| API and web app same-site in every deployed environment | Deployment | **To confirm** — constraint introduced by D-18, see R-1 |
| iOS/Android runtime available to verify AC-3.6 | Developer | **To confirm** — see R-6 |

**Depended on by**

- **Prompt 05 (concert domain API)** — every concert is scoped to a `User`; this entity is its owner.
- **Prompt 08 (backoffice)** — builds on AC-10.3/AC-10.4 and on `User` existing.
- **Prompt 10 (streaming account linking)** — `StreamingAccount` hangs off `User`, and D-19's flag is
  a candidate gate there.
- **Every later frontend prompt** — all product screens live inside the `(app)` group this creates.

**Assumptions** *(labelled as assumptions, not verified facts)*

- LexikJWT has a release compatible with Symfony 8.1 on PHP 8.4. If not, R-2 applies.
- Deep links for the password-reset email work under Expo Router on all three platforms without a
  custom native module; on web the link is an ordinary URL.
- `openapi-typescript` handles the new operations (including the 401/422/429 responses) as cleanly as
  it handled `/api/health`.
- The dummy-hash approach (AC-9.4) narrows the timing gap enough for this threat model; a
  cryptographically constant-time login is out of proportion here.

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **The httpOnly-cookie approach (D-18) fails against a real deployment topology** — API and web app end up cross-site, or a CDN/proxy strips or rewrites `Set-Cookie` | High — the web session breaks in production while working locally | Confirm the same-site topology *before* step 7. Verify the full login→refresh cycle on web against the running stack, not only in tests. If the topology genuinely cannot be same-site, **stop and report**; the fallback (memory-only access token with re-login on reload, no persisted web session) is a product regression and needs a decision, not a workaround |
| R-2 | **LexikJWT or another auth dependency is not yet Symfony 8.1 / PHP 8.4 compatible** | High — blocks the whole backend half | Resolve dependencies first, before writing code (the backend skeleton spec's R-1 pattern). If Lexik is unavailable, report before improvising a hand-rolled JWT layer — signing and validating tokens by hand is exactly the code that should not be bespoke |
| R-3 | **Reuse detection produces false positives**, killing legitimate sessions — a dropped response, an offline retry, or two tabs refreshing at once each look like replay | High for felt quality — users logged out at random with no explanation is worse than the attack being mitigated | The client-side single-flight guard (AC-4.5) removes the common cause. Server-side, allow a short grace window (a few seconds) during which the *immediately* preceding token of a family returns the current token pair rather than killing the family; anything older is treated as replay. Test both the false-positive and true-positive paths |
| R-4 | **The rate limiter locks out a legitimate user** (shared IP, office NAT, a person with a genuinely forgotten password) | Medium | Per-identifier limits are tighter than per-IP ones (AC-9.1) so a shared IP does not punish individuals; 429 carries `Retry-After` and the client explains it (AC-9.2). Limits are config, not code, and tunable after real traffic |
| R-5 | **A secret leaks through a path nobody thought to check** — a validation error echoing the submitted password, a serialized exception, a third-party log sink | High — this is the failure mode that ends products | US-11's tests cover the known paths (serialization, logs, client console). A `devops-security-engineer` review of the diff before the PR merges is strongly recommended, since this branch is the entire credential surface of the product |
| R-6 | **iOS/Android cannot actually be exercised**, leaving AC-3.4 and AC-3.6 unverified claims | Medium — secure-store behaviour is the one thing that genuinely differs per platform | Confirm device/simulator availability before starting. If a platform cannot be exercised, **record which platforms were verified in the PR** rather than marking the criteria met |
| R-7 | **Email delivery is unreliable or slow in production**, so password reset — the only recovery path — silently fails for some users | High whenever it happens; invisible until a user complains | D-20 keeps the provider swappable. Dispatch failures are logged as errors, verification resend exists (AC-7.3), and delivery monitoring goes on the operational checklist for the first deploy |
| R-8 | **Deep links for the reset email do not work on one platform**, stranding users mid-reset | Medium | Ship the web reset URL as the canonical link and treat the native app-link interception as an enhancement; verify per platform and record the result |
| R-9 | **Scope creep into profile management** — "while we are in here, add change-password/change-email" | Medium — email change re-opens verification and enumeration, and would double this branch | The Out of Scope table is binding. A follow-up prompt covers it |
| R-10 | **Token tables grow without bound**, degrading refresh performance | Low | `app:tokens:prune` plus indexes on `expiresAt` and `family`; scheduling documented |

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (run `/doc-check` before committing):

- **`docs/env-vars.md`** *and* **`backend/.env.example`** — `MAILER_DSN`,
  `AUTH_REQUIRE_VERIFIED_EMAIL`, and confirmation of the JWT/refresh variables' now-real meaning.
  Both files or neither.
- **`docs/architecture.md`** — record **D-18**–**D-23** in the Decisions section; extend §11
  (Security posture) with the token model (short access token, rotating hashed refresh token,
  family-based reuse detection) and the cookie/secure-store split; confirm §9's console-only admin
  provisioning now exists in fact.
- **Root `README.md`** — the new `mailpit` service, its UI at <http://localhost:8025>, and the
  first-run step of generating the JWT keypair.
- **`frontend/README.md`** — the session module as the only token owner, the storage-adapter
  platform-branch exception to AC-1.8, the D-15 gate outcome for `expo-secure-store`, and the rule
  that no screen touches a token.
- **`CLAUDE.md`** — no change expected; add a Setlistify-specific rule only if the "one session
  module" or "no admin via public API" rules deserve to sit alongside the streaming-port rules.
- **No endpoint list in any README** — the regenerated OpenAPI document stays the single source of
  truth for all nine endpoints.

---

## Review

**This spec needs your approval before implementation begins.**

Six decisions are made on your behalf; three of them resolve the open questions the prompt raised
and all six get more expensive to reverse once prompts 05, 08 and 10 build on them:

1. **D-18** — web tokens: refresh in an httpOnly `SameSite=Strict` cookie, access token in memory.
   Accepts a **same-site deployment constraint** (API and web app on one site) and one
   platform-branched storage adapter.
2. **D-19** — email verification is built now but **not mandatory at MVP**, enforced through a flag
   defaulting to off, with prompt 10 flagged as the natural place to turn it on.
3. **D-20** — **Mailpit** in compose for development; production mail is a DSN, not an SDK.
4. **D-21** — a custom refresh-token implementation rather than
   `gesdinet/jwt-refresh-token-bundle`, because that bundle stores tokens in plaintext and offers
   neither rotation nor reuse detection.
5. **D-22** — the `User` entity is never a writable API resource; DTOs bound every public payload,
   which is what makes the `ROLE_ADMIN` guarantee structural.
6. **D-23** — enumeration resistance is total on login and reset, and **deliberately partial on
   registration** (a taken email returns 422), traded for a usable signup and bounded by rate limits.

Also worth an explicit yes/no before implementation: the **TTLs** (15-minute access, 30-day refresh,
60-minute reset, 24-hour verification) and the **rate-limit values** in AC-9.1 — both are easy to
change later, but they are the numbers that will be built and tested against.
