# Environment variables and credential separation

**Rule zero: `.env.example` is the only environment file that ever enters git.** It carries variable
names and obviously-fake placeholder values. Real values live in a secret store, never in the repo,
never in a commit message, never in a log line, never in a screenshot pasted into an issue.

## The boundary: env-var or backoffice flag?

Setlistify has two places configuration can live, and putting a value in the wrong one is a security
bug or an operational one.

| Kind of value | Lives in | Examples |
|---|---|---|
| **Secrets and endpoints** — anything that grants access, or that cannot change without a deploy | **Environment / secret store** | `SPOTIFY_CLIENT_SECRET`, `DATABASE_URL`, `TOKEN_ENCRYPTION_KEY` |
| **Behaviour** — anything an operator may need to change at 3am without shipping code | **Backoffice** (`ProviderSetting`) | provider `enabled`, `playbackMode`, `isDefault` |

If a value is a credential, it does not belong in the database. If a value needs changing during an
incident, it does not belong in an env var. The backoffice must never render a secret, not even
masked — see `docs/architecture.md` §9.

## Where values live, per environment

| Environment | Source of values | Notes |
|---|---|---|
| **Local dev** | `backend/.env.local`, `frontend/.env.local` — both gitignored | Populate by copying `.env.example`. Symfony's secrets vault (`symfony console secrets:set`) for anything sensitive enough to want encrypted at rest locally. |
| **CI** | Repository/organization secrets | Only what tests genuinely need. Integration tests against real providers use dedicated test credentials, never production ones. |
| **Production** | PaaS platform secret store | Injected at runtime. No `.env` file is deployed. Rotation is a platform operation, not a redeploy. |

**Separate OAuth app registrations for dev and production.** Two Spotify apps, two Google Cloud OAuth
clients. A leaked development key must not be able to reach production data, and revoking one must
not take the other down. Redirect URIs are registered per environment.

## Variables

### Core

| Variable | Secret | Purpose |
|---|---|---|
| `APP_ENV` | no | `dev` \| `test` \| `prod` |
| `APP_SECRET` | **yes** | Symfony framework secret. Distinct per environment. |
| `DATABASE_URL` | **yes** | PostgreSQL DSN, contains credentials |
| `REDIS_URL` | **yes** | Redis DSN — cache, Messenger transport, rate limiter |
| `MESSENGER_TRANSPORT_DSN` | **yes** | Async transport for playlist build jobs |
| `CORS_ALLOW_ORIGIN` | no | Permitted client origins. Never `*` in production. |

### Authentication

| Variable | Secret | Purpose |
|---|---|---|
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | **yes** | API token signing keypair paths |
| `JWT_PASSPHRASE` | **yes** | Passphrase for the private key |
| `JWT_TTL` | no | Access-token lifetime, seconds. `900` (15 min) — AC-2.2 |
| `REFRESH_TOKEN_TTL` | no | Refresh-token lifetime, seconds. `2592000` (30 days) — AC-2.2 |
| `EMAIL_VERIFICATION_TOKEN_TTL` | no | Email verification token lifetime, seconds. `86400` (24h) — AC-7.1 |
| `PASSWORD_RESET_TOKEN_TTL` | no | Password reset token lifetime, seconds. `3600` (60 min) — AC-6.2 |
| `AUTH_REQUIRE_VERIFIED_EMAIL` | no | D-19: gates the email-verification security attribute. Default `false` — verification is built but not enforced at MVP |
| `AUTH_COOKIE_SECURE` | no | `Secure` flag on the refresh-token cookie (D-18). `true` in every real deployment; browsers treat `localhost` as a secure context so `true` also works for local dev |
| `MAILER_DSN` | **yes** | Symfony Mailer DSN. `smtp://mailpit:1025` in dev (D-20); a real provider DSN in production. No provider SDK in application code |
| `MAILER_FROM_ADDRESS` | no | `From:` address on verification/reset emails |
| `WEB_APP_URL` | no | Base URL of the web app — used to build the links inside verification/reset emails (AC-6.8) |
| `TOKEN_ENCRYPTION_KEY` | **yes** | libsodium key encrypting **users' provider OAuth tokens** at rest — the **active** key. See below. |
| `TOKEN_ENCRYPTION_KEY_ID` | no | The key id stamped into every **new** ciphertext. Default `v1`. |
| `TOKEN_ENCRYPTION_KEYS_RETIRED` | **yes** | Comma-separated `id:base64key` pairs — retired keys still valid for **decryption only**. Empty by default. See below. |

### setlist.fm

| Variable | Secret | Purpose |
|---|---|---|
| `SETLISTFM_API_KEY` | **yes** | API key |
| `SETLISTFM_DAILY_BUDGET` | no | Daily request ceiling enforced locally. Default `1440`; raise only after setlist.fm grants a higher tier (`docs/specs/2026-08-22-setlistfm-integration.md` D-69). |
| `SETLISTFM_RATE_PER_SECOND` | no | Token-bucket rate, default `2` |
| `SETLISTFM_CACHE_TTL` | no | Redis tier TTL, seconds. Default `300` |
| `SETLISTFM_BASE_URL` | no | Default `https://api.setlist.fm/rest/1.0`. Overridden in `test` and by the `@group live` smoke test so neither needs a code change to point elsewhere |
| `SETLISTFM_HTTP_TIMEOUT` | no | Total request timeout, seconds. Default `5` (AC-9.1) |
| `SETLISTFM_TOKEN_WAIT` | no | Max seconds a request waits for a rate-limit token before degrading to cache with `rate_limited` (AC-7.5). Default `1` |
| `SETLISTFM_REFRESH_BUDGET_SHARE` | no | Share of the daily budget the nightly `app:setlist:refresh` job may spend. Default `0.25` (AC-10.3) |

### Streaming providers

Credentials only. Whether a provider is **active**, and how playback is rendered, are backoffice
flags — not variables.

| Variable | Secret | Purpose |
|---|---|---|
| `SPOTIFY_CLIENT_ID` | no (but do not publish) | OAuth client id |
| `SPOTIFY_CLIENT_SECRET` | **yes** | OAuth client secret |
| `SPOTIFY_REDIRECT_URI` | no | Per-environment, must match the registered app exactly (AC-1.9 — exactly one, the backend's own callback). Spotify rejects `localhost` in this value — use the literal loopback IP `127.0.0.1` for local development (its authorization server otherwise returns `redirect_uri: Not matching configuration` even when the path is correct), and register that exact URI in the Spotify Developer Dashboard for the app behind `SPOTIFY_CLIENT_ID` |
| `SPOTIFY_API_BASE_URL` | no | Default `https://api.spotify.com/v1/`. **Must end with a trailing slash** — Symfony's HttpClient resolves the (deliberately leading-slash-free) request paths in `SpotifyProvider` relative to this base URI per RFC 3986, and a missing trailing slash silently drops the `/v1` segment, turning every Spotify Web API call into a 404/410 against the bare host. Overridden in `test` and by the `@group live` smoke test so neither needs a code change to point elsewhere |
| `SPOTIFY_ACCOUNTS_BASE_URL` | no | Default `https://accounts.spotify.com` — the OAuth endpoints, same reason as above |
| `STREAMING_HTTP_TIMEOUT` | no | Provider-agnostic outbound request timeout, seconds. Default `5` |
| `STREAMING_TOKEN_REFRESH_SKEW` | no | Refresh a token this many seconds before it expires. Default `60` |
| `STREAMING_LINK_STATE_TTL` | no | Pending-link `state` lifetime in Redis, seconds. Default `600` |
| `STREAMING_LINK_RETURN_URL_WEB` | no | Web return route after the OAuth callback completes |
| `STREAMING_LINK_RETURN_URL_NATIVE` | no | Native deep link after the OAuth callback completes |
| `YOUTUBE_CLIENT_ID` | no | Google OAuth client id |
| `YOUTUBE_CLIENT_SECRET` | **yes** | Google OAuth client secret |
| `YOUTUBE_REDIRECT_URI` | no | Per-environment |
| `YOUTUBE_DAILY_QUOTA_UNITS` | no | Locally-enforced ceiling, default `10000` |
| `APPLE_TEAM_ID` / `APPLE_KEY_ID` | no | MusicKit, future |
| `APPLE_PRIVATE_KEY` | **yes** | MusicKit ES256 signing key, future |

### Local container identity (root `.env`, not `backend/.env.local`)

These two are read by **Docker Compose itself**, not by the application, so they live in a `.env` at
the repository root (copied from the root `.env.example`) rather than in `backend/.env.local`. They
are machine-specific and gitignored.

| Variable | Secret | Purpose |
|---|---|---|
| `APP_UID` | no | uid the `backend`/`worker` containers run as (`docker/backend/Dockerfile` build arg). Default `1000`. |
| `APP_GID` | no | gid, same. Default `1000`. |

`./backend` is bind-mounted into both containers, so **anything the container writes there is owned
by this uid on the host too** — Composer installs, the Symfony cache, generated migrations. If it does
not match your host user, those files become unwritable by you: editors fail with `EACCES`, and
`git stash`/`git checkout` fail partway through a branch switch because git cannot unlink them,
leaving `HEAD` on one branch and the working tree on another.

Set them once per clone, in the root `.env` so the value survives new shells and rebuilds:

```bash
printf 'APP_UID=%s\nAPP_GID=%s\n' "$(id -u)" "$(id -g)" >> .env
docker compose build backend worker && docker compose up -d --force-recreate backend worker
```

An `export` in one shell is not enough — the value is a **build arg**, so a stale image keeps the old
uid until it is rebuilt.

### Playlist generation

Numeric tuning constants for the generation pipeline (`docs/specs/2026-08-23-spike-playlist-
pipeline.md`, `docs/specs/2026-08-23-playlist-fast-mode-backend.md`). None are secrets.

| Variable | Secret | Purpose |
|---|---|---|
| `PLAYLIST_WORKER_COUNT` | no | Number of `messenger:consume async_playlist` worker replicas (`compose.yaml`). Default `2`. |
| `GENERATION_MAX_BANDS` | no | P-1: caps a multi-band concert to its highest-billed N bands. Default `4`. |
| `GENERATION_MAX_SONGS` | no | P-1: caps the total songs across a generation, cutting from the lowest-billed end. Default `60`. |
| `GENERATION_SETLIST_PAGES` | no | D-131: at most this many setlist.fm index pages spent per band per generation. Default `1` — never a per-setlist detail fetch, never a speculative freshness check. |
| `SUSPENDED_SETLIST_CHOICE_TTL` | no | P-4: seconds a Normal-mode `awaiting_setlist_choice` job survives before `app:playlist:expire-jobs` expires it. Default `604800` (7 days). No effect on Fast mode. |
| `SUSPENDED_VERSION_CHOICE_TTL` | no | P-4: same, for `awaiting_version_choice`. Default `259200` (72 hours). No effect on Fast mode. |
| `GENERATION_INSERT_BATCH_SIZE` | no | Provider `addTracks()` batch size (spec 13 §4). Default `50`. |
| `GENERATION_MAX_BLOCK_CYCLES` | no | T-14: a `blocked` job past this many resume cycles moves to `failed` instead. Default `3`. |
| `GENERATION_RATE_LIMIT_INLINE_RETRIES` | no | F-05: inline retries on a provider rate limit before blocking. Default `3`. |

### Backoffice provider configuration

Whether a provider is **enabled**, and how playback is rendered, are backoffice flags
(`ProviderSetting` — `docs/specs/2026-08-22-backoffice-provider-configuration.md`), never variables.
No credential is ever configurable from `/admin` — this table has exactly one row because credentials
stay entirely in the "Streaming providers" table above.

| Variable | Secret | Purpose |
|---|---|---|
| `PROVIDER_SETTINGS_CACHE_TTL` | no | Backstop TTL (seconds) on the Redis-cached provider settings snapshot. Default `300`. Correctness comes from explicit invalidation on every write (D-92) — this exists only to bound the damage of a hypothetical invalidation bug, not to make a stale read tolerable by design. |

### Backoffice

| Variable | Secret | Purpose |
|---|---|---|
| `ADMIN_PATH_PREFIX` | no | Default `/admin`. Changing it is obscurity, not security — the firewall does the work. **Compiled into the container** (D-48): Symfony bakes firewall patterns and admin route paths into the compiled container, so changing this value requires a cache clear/rebuild, not just an env change and a process restart. |
| `ADMIN_TOTP_ISSUER` | no | Issuer name shown in the operator's authenticator app |
| `ADMIN_IP_ALLOWLIST` | no | Comma-separated CIDR ranges (e.g. `203.0.113.0/24,198.51.100.7/32`). **Empty means unrestricted** (correct for local dev and CI); **non-empty enforces it** — a request from outside every listed range gets a plain 404 before authentication runs (D-42), not a 403, so an outsider can't confirm the prefix exists. A startup check logs an `error` if `APP_ENV=prod` and this is empty. Populating it is a production deployment requirement. |
| `ADMIN_TOTP_ENCRYPTION_KEY` | **yes** | Base64-encoded 32-byte libsodium `xchacha20poly1305` key encrypting the admin's TOTP secret at rest (AC-5.3) — same scheme as `TOKEN_ENCRYPTION_KEY` below, generated per environment, never reused across purposes |

### Frontend (Expo)

Anything reaching an Expo client is **public** — it ships inside the app bundle. Never put a secret
here. Prefixed `EXPO_PUBLIC_` precisely so this is impossible to forget.

| Variable | Secret | Purpose |
|---|---|---|
| `EXPO_PUBLIC_API_URL` | no | Backend base URL |
| `EXPO_PUBLIC_ENV` | no | Environment label for logging/telemetry |

Provider availability and playback mode are **not** frontend variables — the client fetches them at
startup from `GET /api/config/providers`, so an operator's backoffice change reaches users without an
app-store release.

## Encrypting users' provider tokens

A user's Spotify or YouTube OAuth tokens are live credentials to *their* account. A database dump
must not be a set of usable streaming credentials.

- Encrypted at rest with libsodium `xchacha20poly1305`, applied through a custom Doctrine type
  (`App\Doctrine\Type\EncryptedStringType`) so encryption is not something a developer can forget
  to call — persisting a `StreamingAccount`'s token columns any other way isn't possible.
- `TOKEN_ENCRYPTION_KEY` is 32 bytes, base64-encoded, generated per environment, and stored only in
  the secret store.
- Key rotation is supported from the start (`docs/specs/2026-08-22-streaming-port-and-account-linking.md`,
  D-78): the envelope is `v1:<keyId>:<base64(nonce‖ciphertext)>`. `TOKEN_ENCRYPTION_KEY_ID` names
  the **active** key every new write uses; `TOKEN_ENCRYPTION_KEYS_RETIRED` (`id:base64key` pairs,
  comma-separated) holds predecessors still valid for **decryption only**. To rotate: move the
  outgoing key into `TOKEN_ENCRYPTION_KEYS_RETIRED`, set a new `TOKEN_ENCRYPTION_KEY`/
  `TOKEN_ENCRYPTION_KEY_ID` pair, deploy — no downtime, no migration. Decrypting a ciphertext whose
  key id is in neither set fails loudly (`App\Service\Security\UnknownEncryptionKeyException`),
  never silently as a missing/empty token.
- Tokens are never logged, never returned by any API endpoint, and never rendered in the backoffice.

## Handling a leak

If a credential reaches the repository, a log, or any third party:

1. **Revoke and rotate at the provider first.** Removing the commit does nothing — assume it was
   scraped the moment it was pushed.
2. Rotate the value in every environment that shared it.
3. Purge from git history (`git filter-repo`), then force-push and notify anyone with a clone.
4. If `TOKEN_ENCRYPTION_KEY` leaked, rotate it and re-encrypt every stored provider token; treat
   users' provider tokens as compromised and require re-linking.
5. Record what happened and what changed, in `docs/`.

## Checklist for adding a variable

- [ ] Is it a secret, or is it behaviour that belongs in the backoffice instead?
- [ ] Added to `.env.example` with a placeholder that is obviously not real.
- [ ] Added to the table above with its purpose and secret status.
- [ ] Set in CI (if tests need it) and in the production secret store.
- [ ] Different value per environment, if it is a credential.
- [ ] Confirmed it is not logged, not serialized into an API response, and not shown in `/admin`.
