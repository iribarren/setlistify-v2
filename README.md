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

# Bring the stack up
docker compose up -d
docker compose ps   # postgres, redis and backend should all report "healthy" within ~90s
```

Install the pre-commit secret-scan hook once per clone (see
[Secret scanning](#secret-scanning-before-you-commit)):

```bash
git config core.hooksPath .githooks
```

## Services

| Service | URL |
|---|---|
| Backend API (health probe today; API Platform lands in a later prompt) | <http://localhost:8000> |
| PostgreSQL | internal only — `docker compose exec postgres psql -U setlistify -d setlistify` |
| Redis | internal only — `docker compose exec redis redis-cli` |

`postgres` and `redis` publish no host port (least exposure — see `docs/architecture.md` §11); reach
them through `docker compose exec`, or add a port mapping in a local, gitignored
`compose.override.yaml` if you need a GUI client.

The OpenAPI spec (once the backend exists) is generated from the API Platform resources — it is the
single source of truth for endpoints. This README intentionally lists none; see `CLAUDE.md`, API
Contract.

## Frontend (runs on the host)

```bash
cd frontend && npx expo start        # then press w / i / a, or scan the QR code
```

The frontend is **deliberately not containerized** (decision D-3, `docs/architecture.md`): Expo's
tooling — the Metro bundler, simulator/device access, QR pairing over the LAN — works poorly through
container networking, and containerizing it would cost a day of fighting Docker for no benefit. Run
it natively; it reaches the containerized backend at `EXPO_PUBLIC_API_URL`
(`frontend/.env.example`), already pointed at `http://localhost:8000`.

Testing on a physical device? `localhost` resolves to the device itself, not your machine. Set
`EXPO_PUBLIC_API_URL` in `frontend/.env.local` to your machine's LAN IP instead
(e.g. `http://192.168.1.23:8000`).

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
