# Setlistify

Setlistify turns concerts you attend into streaming playlists, built from what the bands actually
played. Track a concert (bands, date, venue, ticket price), Setlistify pulls the band's real
setlists from [setlist.fm](https://www.setlist.fm/) and generates a matching playlist in your
streaming service of choice. After the show, the concert page is where you replay it and write down
what it was like.

This repository is a monorepo: the backend API, the cross-platform client, container definitions and
project documentation all live here. See `docs/architecture.md` for the system design and
`docs/prompts/README.md` for the implementation roadmap.

## Prerequisites

| Tool | Minimum version | Used for |
|---|---|---|
| Docker Engine + Compose v2 | Engine 24+, Compose v2 (`docker compose`, not `docker-compose`) | The backend stack: PostgreSQL, Redis, backend |
| Node.js | LTS (20.x or newer) | The Expo frontend, which runs on the host, not in a container |
| npm or pnpm | npm 10+ / pnpm 9+ | Frontend package management |

Docker is not required to run the frontend — see [Frontend (runs on the host)](#frontend-runs-on-the-host)
below.

## Getting started

```bash
git clone <repo-url>
cd setlistify-v2

# Configure — copy the example env files. Both are gitignored once copied; never commit them.
cp backend/.env.example backend/.env.local
cp frontend/.env.example frontend/.env.local

# On Linux/macOS, make containers write files back as your own user, not root
# (skip this if your host user's UID/GID happen to already be 1000/1000).
export APP_UID=$(id -u)
export APP_GID=$(id -g)

# Generate a local JWT signing keypair for auth (gitignored, never committed — docs/env-vars.md).
# Passphrase must match JWT_PASSPHRASE in backend/.env.local.
mkdir -p backend/config/jwt
openssl genpkey -algorithm RSA -out backend/config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -aes256
openssl pkey -in backend/config/jwt/private.pem -pubout -out backend/config/jwt/public.pem

# Bring the stack up
docker compose up -d
docker compose ps   # postgres, redis, mailpit and backend should all report "healthy" within ~90s

# Install backend dependencies (with dev tools) and apply migrations — see backend/README.md
docker compose exec backend composer install
docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction
```

Install the pre-commit secret-scan hook once per clone (see
[Secret scanning](#secret-scanning-before-you-commit)):

```bash
git config core.hooksPath .githooks
```

## Services

| Service | URL |
|---|---|
| Backend API | <http://localhost:8000/api> |
| Health check | <http://localhost:8000/api/health> |
| OpenAPI docs (UI) | <http://localhost:8000/api/docs> |
| OpenAPI document (JSON) | <http://localhost:8000/api/docs.jsonopenapi> |
| Backoffice (operator only — see [Backoffice](#backoffice)) | <http://localhost:8000/admin> |
| Frontend (web) | <http://localhost:8081> |
| Mailpit (dev mail sink — verification/reset emails) | <http://localhost:8025> |
| PostgreSQL | internal only — `docker compose exec postgres psql -U setlistify -d setlistify` |
| Redis | internal only — `docker compose exec redis redis-cli` |

`postgres` and `redis` publish no host port (least exposure — see `docs/architecture.md` §11); reach
them through `docker compose exec`, or add a port mapping in a local, gitignored
`compose.override.yaml` if you need a GUI client.

The OpenAPI document, generated from the API Platform resources, is the single source of truth for
endpoints — this README intentionally lists none beyond the URLs above; see `CLAUDE.md`, API
Contract, and `backend/README.md` for the backend command set.

## Backoffice

Server-rendered inside the Symfony app (`docs/architecture.md` §9, §11) — never in the Expo client,
never in the OpenAPI document. Every route requires `ROLE_ADMIN` and a completed TOTP enrollment;
there is no self-service signup or promotion path.

First run:

```bash
docker compose exec backend bin/console app:admin:create you@example.test 'a-strong-password'
```

Then open <http://localhost:8000/admin/login> and sign in with that password — an account with no
TOTP secret yet is redirected straight to enrollment (scan the QR code or enter the secret manually,
save the ten backup codes shown, confirm with the current 6-digit code). After that, the account is
fully usable.

**Lost your authenticator app or backup codes?** There is no web-based recovery flow by design
(`docs/architecture.md` D-49) — only shell access can reset 2FA:

```bash
docker compose exec backend bin/console app:admin:2fa:reset you@example.test
```

This clears the TOTP secret and every backup code; the next login re-enrolls with a brand new
secret and a brand new set of codes.

## Operations

**Nightly setlist.fm refresh** (`docs/architecture.md` §5, D-65) — the only thing allowed to spend
setlist.fm budget speculatively. Not scheduled from inside the app (no `symfony/scheduler`
dependency); the deployment target's own cron invokes it once a night:

```bash
docker compose exec backend bin/console app:setlist:refresh
```

Safe to run more than once a night (idempotent, AC-10.8) and safe to run concurrently (guarded by a
`symfony/lock`, a second overlapping run exits immediately). Its last outcome is visible on the
backoffice dashboard (budget spent, bands attempted, setlists written), flagged if it hasn't run in
over 36 hours.

**setlist.fm live smoke test** (`docs/specs/2026-08-22-setlistfm-integration.md`, AC-13.3, D-70) —
the default test suite never calls setlist.fm (`docs/architecture.md` D-2); one test tagged
`@group live` does, deliberately, to catch a real API shape change before a release. Run it manually,
never on a schedule (a scheduled live test is itself a scheduled budget spend), with a real
`SETLISTFM_API_KEY` set:

```bash
docker compose exec backend vendor/bin/phpunit --group live
```

## Frontend (runs on the host)

An Expo + Expo Router + TypeScript app — one codebase for web, iOS and Android. See
`frontend/README.md` for the full command set, the design-token rule, and the generated-API-client
workflow.

```bash
cd frontend && npm install
npx expo start        # then press w / i / a, or scan the QR code with Expo Go
```

Opens at <http://localhost:8081> on web. The frontend is **deliberately not containerized**
(decision D-3, `docs/architecture.md`): Expo's tooling — the Metro bundler, simulator/device access,
QR pairing over the LAN — works poorly through container networking, and containerizing it would
cost a day of fighting Docker for no benefit. Run it natively; it reaches the containerized backend
at `EXPO_PUBLIC_API_URL` (`frontend/.env.example`), already pointed at `http://localhost:8000`.

Testing on a physical device? `localhost` resolves to the device itself, not your machine. Set
`EXPO_PUBLIC_API_URL` in `frontend/.env.local` to your machine's LAN IP instead
(e.g. `http://192.168.1.23:8000`).

**After any backend API change**, regenerate the typed client in the same branch (`CLAUDE.md`, API
Contract):

```bash
docker compose exec backend bin/console api:openapi:export --output=openapi.json
docker compose cp backend:/app/openapi.json backend/openapi.json
cd frontend && npm run generate:api
```

CI re-checks this on every push and fails the build if `frontend/api/` has drifted from the backend
contract.

## Tearing down

```bash
docker compose down       # stops containers, keeps postgres_data / redis_data (your data is safe)
docker compose down -v    # also removes the named volumes — full reset, data is gone
```

## Secret scanning before you commit

A [gitleaks](https://github.com/gitleaks/gitleaks) pre-commit hook (`.githooks/pre-commit`,
configured by `.gitleaks.toml`) blocks any commit containing a credential-shaped string. It runs via
Docker, so no local gitleaks install is needed. Install it once per clone:

```bash
git config core.hooksPath .githooks
```

The same scan runs again in CI over the full pushed branch (`.github/workflows/ci.yml`,
`secret-scan` job), so a commit made with `--no-verify` — or on a machine where the hook was never
installed — is still caught before merge.

If a credential ever does reach the repository, a log, or a third party: **revoke and rotate it at
the provider first**; see `docs/env-vars.md`, "Handling a leak", for the full procedure.

## Environments

| Environment | Where values come from |
|---|---|
| Local dev | `backend/.env.local`, `frontend/.env.local` — gitignored, populated from `.env.example` |
| CI | Repository/organization secrets — only what a stub or future test genuinely needs. CI never calls a real external API (setlist.fm, Spotify, YouTube); see `docs/architecture.md`, decision D-2 |
| Production | The PaaS platform secret store, injected at runtime. **No `.env` file is ever deployed.** Rotation is a platform operation, not a redeploy |

Development and production use **separate OAuth app registrations** per streaming provider (separate
Spotify apps, separate Google Cloud OAuth clients), each with its own redirect URIs — a leaked
development credential cannot reach production data, and revoking one never takes the other down.

No production account, DNS entry, PaaS project or production credential exists yet; this repository
only defines the shape production configuration will take. See `docs/env-vars.md` for the full
variable reference and credential-separation rules.

## Further reading

- [`docs/architecture.md`](docs/architecture.md) — system design and technical decisions
- [`docs/env-vars.md`](docs/env-vars.md) — every environment variable, what's a secret, and where
  values live per environment
- [`docs/external-apis.md`](docs/external-apis.md) — setlist.fm, Spotify, YouTube and Apple Music
  integration notes, quotas and legal posture
- [`docs/prompts/README.md`](docs/prompts/README.md) — the implementation roadmap, phase by phase
