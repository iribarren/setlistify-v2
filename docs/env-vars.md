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
| `JWT_TTL` | no | Access-token lifetime, seconds |
| `REFRESH_TOKEN_TTL` | no | Refresh-token lifetime, seconds |
| `TOKEN_ENCRYPTION_KEY` | **yes** | libsodium key encrypting **users' provider OAuth tokens** at rest. See below. |

### setlist.fm

| Variable | Secret | Purpose |
|---|---|---|
| `SETLISTFM_API_KEY` | **yes** | API key |
| `SETLISTFM_DAILY_BUDGET` | no | Daily request ceiling enforced locally. Default `1440`; raise only after setlist.fm grants a higher tier. |
| `SETLISTFM_RATE_PER_SECOND` | no | Token-bucket rate, default `2` |
| `SETLISTFM_CACHE_TTL` | no | Redis tier TTL, seconds |

### Streaming providers

Credentials only. Whether a provider is **active**, and how playback is rendered, are backoffice
flags — not variables.

| Variable | Secret | Purpose |
|---|---|---|
| `SPOTIFY_CLIENT_ID` | no (but do not publish) | OAuth client id |
| `SPOTIFY_CLIENT_SECRET` | **yes** | OAuth client secret |
| `SPOTIFY_REDIRECT_URI` | no | Per-environment, must match the registered app exactly |
| `YOUTUBE_CLIENT_ID` | no | Google OAuth client id |
| `YOUTUBE_CLIENT_SECRET` | **yes** | Google OAuth client secret |
| `YOUTUBE_REDIRECT_URI` | no | Per-environment |
| `YOUTUBE_DAILY_QUOTA_UNITS` | no | Locally-enforced ceiling, default `10000` |
| `APPLE_TEAM_ID` / `APPLE_KEY_ID` | no | MusicKit, future |
| `APPLE_PRIVATE_KEY` | **yes** | MusicKit ES256 signing key, future |

### Backoffice

| Variable | Secret | Purpose |
|---|---|---|
| `ADMIN_PATH_PREFIX` | no | Default `/admin`. Changing it is obscurity, not security — the firewall does the work. |
| `ADMIN_TOTP_ISSUER` | no | Issuer name shown in the operator's authenticator app |
| `ADMIN_IP_ALLOWLIST` | no | Optional CIDR allowlist, production hardening |

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

- Encrypted at rest with libsodium `xchacha20poly1305`, applied through a custom Doctrine type so
  encryption is not something a developer can forget to call.
- `TOKEN_ENCRYPTION_KEY` is 32 bytes, base64-encoded, generated per environment, and stored only in
  the secret store.
- Key rotation must be supported from the start: store a key id alongside each ciphertext so old
  records stay readable while new ones use the current key.
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
