---
name: project_auth_and_accounts
description: Non-obvious gotchas hit implementing auth (JWT/refresh rotation/mailer/rate-limiter) on the Setlistify backend. Read before touching login/refresh/logout/reset/verification code.
metadata:
  type: project
---

Implemented on `feature/auth-and-accounts` (docs/specs/2026-08-21-auth-and-accounts.md), building on
[[project_symfony_skeleton]]. Several things here cost real debugging time and are not obvious from
reading the code — check this before assuming a similar change "should just work".

**API Platform resources must not share an `input:` DTO class across different resources.** Giving
`LogoutAction` and `RefreshOutput` the same `input: RefreshInput::class` caused requests to
`/api/logout` to silently execute `RefreshProcessor`'s logic instead of `LogoutProcessor`'s — route
matching was correct (`_api_resource_class` in logs showed `LogoutAction`) but the wrong processor
ran. Root-caused by giving each resource its own input class (`LogoutInput` vs `RefreshInput`,
structurally identical). Not fully explained (looked like an API Platform metadata cache keyed by
input FQCN), but the fix is cheap and safe: **one input class per resource, even if two resources'
bodies are structurally identical.**

**A cookie's `Path` genuinely gates which routes receive it — including in this app's own logout
flow.** D-18 originally scoped the refresh cookie to `Path=/api/token/refresh` only. `/api/logout`
needs to read the same cookie to know which token family to revoke, but a browser (and curl) simply
never sends a `Path`-scoped cookie to a different path. Fixed by widening the cookie to
`Path=/api`, documented as a deviation in `docs/architecture.md` D-18 — only `RefreshProcessor` and
`LogoutProcessor` actually read it, so the practical CSRF argument is unaffected. **If a future
endpoint needs to read this cookie, it must be under `/api` — the cookie is not narrower than that.**

**LexikJWTAuthenticationBundle's config key is `user_id_claim` (default `username`), not
`user_identity_field`.** It's read via Symfony's `PropertyAccessor` against the `User` object —
`isReadable($user, $claimName)` decides whether it calls a matching getter or falls back to
`getUserIdentifier()`. To keep an email out of the JWT (AC-2.3) while still using it as
`getUserIdentifier()` for Symfony Security's own bookkeeping, add a *separate* method
(`User::getSub(): int`) and point `user_id_claim: sub` at it — then load the user back by that
numeric id, not by `loadUserByIdentifier()`'s usual `findOneBy([property => $identifier])` against
email. See `App\Service\Security\UserIdProvider` and `config/packages/security.yaml`'s
`app_user_provider`.

**LexikJWT's built-in `RandomJtiEnrichment` (meant to add the `jti` claim automatically) compiled to
an empty enrichment chain in this bundle version/config** — verified via `bin/console debug:container
lexik_jwt_authentication.payload_enrichment` showing zero constructor arguments. Rather than debug the
compiler pass, `jti` is added by a small `lexik_jwt_authentication.on_jwt_created` listener
(`App\EventSubscriber\JwtPayloadSubscriber`). `sub`/`roles`/`iat`/`exp` all work correctly without any
listener — only `jti` needed the workaround.

**`Symfony\Component\Mailer\Mailer::send()` dispatches through Messenger transparently once
`symfony/messenger` is installed at all** (no explicit routing needed) — and doing so fires **two**
`MessageEvent`s per email: one `queued=true` pre-dispatch clone, one real `queued=false` event when
the transport actually runs. `MailerAssertionsTrait::assertEmailCount()` already filters on
`isQueued()` correctly; anything that counts/indexes `getMailerMessages()` by hand must filter the
same way or it double-counts. See `AuthWebTestCase::sentMailerMessages()`.

**The mailer message logger is reset between every `KernelBrowser` request in a test**, not only
between test methods — it's a `kernel.reset`-tagged service. A test doing `register()` then
`login()` then `resend()` as three separate `$client->request()` calls will find the mail logger
holding only the *last* request's events by the time it inspects them; the registration email's
event is already gone. Read/assert a request's email **immediately after that specific request**,
never after a later one in the same test.

**Symfony RateLimiter's `framework.rate_limiter.<name>` config key for the storage pool is
`cache_pool` (default `cache.rate_limiter`), not `storage_service`.** `storage_service` expects a
service that's *already* a `Symfony\Component\RateLimiter\Storage\StorageInterface` (a `CacheStorage`
wrapper), not a raw PSR-6 pool — pointing it at a plain cache pool throws a `TypeError` at first use.
Just name the Redis-backed pool `cache.rate_limiter` (the default `cache_pool` name) and omit
`storage_service` entirely; see `config/packages/cache.yaml` + `config/packages/rate_limiter.yaml`.

**`NotCompromisedPassword`'s validator hits `api.pwnedpasswords.com` over real network in dev/prod**
(by design, AC-1.4) but must be disabled in `test` (`config/packages/validator.yaml`,
`not_compromised_password: false` under `when@test`) — otherwise CI/local test runs make an external
call in violation of D-2, and are flaky/slow. Already wired; if a future password-policy change
touches this file, keep the `when@test` override.

**Doctrine's `->getQuery()->execute()` for a bulk DQL `DELETE` returns `int|string` at the PHPStan
level** (DBAL's `Result::rowCount()` typing), not `int` — a repository method declared `: int` needs
an explicit `\is_int($affected) ? $affected : 0` guard, not a bare `(int)` cast alone (level-9 flags
casting `mixed`).

**CI needs a JWT keypair generated as a step, and every new auth-related env var added to the
`backend` job's `env:` block** (`.github/workflows/ci.yml`) — `phpunit.xml.dist`'s `force="true"`
entries only override the values for the `composer test` step; the earlier `cache:warmup --env=dev`
and `doctrine:migrations:migrate --env=dev` steps read the job's own `env:` block directly. Missing
any of `JWT_TTL`, `REFRESH_TOKEN_TTL`, `MAILER_DSN`, `EMAIL_VERIFICATION_TOKEN_TTL`,
`PASSWORD_RESET_TOKEN_TTL`, `AUTH_REQUIRE_VERIFIED_EMAIL`, `AUTH_COOKIE_SECURE`,
`MAILER_FROM_ADDRESS`, `WEB_APP_URL`, `LOCK_DSN` breaks container compilation in CI even though
local `docker compose` never notices, because `backend/.env.local` papers over it there.

**`scripts/check-env-vars-drift.sh` false-positives on any backtick-wrapped ALL_CAPS token in
`docs/env-vars.md`**, even ones that are not env vars (e.g. a security-attribute name like
`IS_EMAIL_VERIFIED`) — it just regexes for `` `[A-Z][A-Z0-9_]*` `` after `## Variables`. Don't
backtick a non-env-var ALL_CAPS identifier in that file; rephrase without backticks instead.

See [[project_symfony_skeleton]] for the base app conventions (health-check pattern, RFC7807, D-5
env handling) this feature builds on.
