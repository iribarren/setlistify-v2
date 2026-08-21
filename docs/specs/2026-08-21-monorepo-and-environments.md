# Monorepo and Environments

| | |
|---|---|
| **Spec ID** | `2026-08-21-monorepo-and-environments` |
| **Backlog prompt** | `docs/prompts/00-monorepo-and-environments.md` |
| **Command** | `/feature monorepo-and-environments` |
| **Primary agent** | `devops-security-engineer` |
| **Depends on** | — (first prompt in the backlog) |
| **Status** | **Approved** 2026-08-21 — ready for implementation |

---

## Overview

Setlistify is a greenfield monorepo: today the repository contains documentation and nothing else.
This feature lays the foundation every later feature stands on — the directory layout, the local
container stack, the credential layout, and the CI pipeline.

Two outcomes define it:

1. **One command to a running stack.** A developer clones the repository, copies two `.env.example`
   files, runs `docker compose up -d`, and has PostgreSQL, Redis and a responding backend container
   — all reporting *healthy*, not merely *running*.
2. **Credential separation that is structural, not procedural.** The rules in `docs/env-vars.md` —
   `.env.example` is the only environment file in git, secrets never reach a Docker layer or a log
   line, dev and production use separate OAuth app registrations — are enforced by `.gitignore`,
   a secret-scanning pre-commit hook and CI, rather than by remembering to be careful.

This feature ships **infrastructure only**. No Symfony application code (prompt 01) and no Expo
application code (prompt 03). The backend container must start and answer a health probe; it does
not yet serve the API.

## Goals

| Goal | Success looks like |
|---|---|
| Reproducible local environment | `docker compose up -d` from a clean clone yields three healthy services, on any developer machine, with no undocumented steps |
| Structural secret safety | No real credential can be committed without the pre-commit hook and CI both failing |
| Environment isolation | A development build cannot reach production data: distinct credentials, distinct OAuth app registrations, distinct `APP_SECRET` and `TOKEN_ENCRYPTION_KEY` |
| A CI pipeline that exists from commit one | Push to any branch runs install → lint → test → secret scan, and is green |
| Onboarding without tribal knowledge | A new developer reaches a running stack from the root `README.md` alone |

## User Stories

### US-1 — One-command local stack

> **As a** developer joining Setlistify,
> **I want** to bring the whole backend stack up with a single command after cloning,
> **so that** I can start contributing without assembling PostgreSQL, Redis and PHP by hand.

**Acceptance criteria**

- **AC-1.1** From a clean clone, `cp backend/.env.example backend/.env.local`,
  `cp frontend/.env.example frontend/.env.local` and `docker compose up -d` are the only steps
  required to bring the stack up.
- **AC-1.2** `docker compose ps` reports `healthy` for `postgres`, `redis` and `backend` within
  90 seconds of a cold start on a machine with no cached images.
- **AC-1.3** The backend container answers an HTTP health probe on `http://localhost:8000` with a
  2xx status.
- **AC-1.4** `docker compose down && docker compose up -d` preserves database and Redis data;
  `docker compose down -v` discards it. Both are documented.
- **AC-1.5** No step in the setup path executes outside the project tree or inside a scratch/temp
  directory (Execution Policy, `CLAUDE.md`).

### US-2 — Frontend runs on the host, and the docs say so

> **As a** developer working on the Expo client,
> **I want** the frontend explicitly excluded from the container stack,
> **so that** I use Expo's native tooling directly and do not lose a day fighting Metro inside Docker.

**Acceptance criteria**

- **AC-2.1** `compose.yaml` defines no frontend service.
- **AC-2.2** The root `README.md` states that the frontend runs on the host via
  `cd frontend && npx expo start`, and why it is not containerized.
- **AC-2.3** `frontend/.env.example` sets `EXPO_PUBLIC_API_URL` to the containerized backend's
  host-visible URL (`http://localhost:8000`), so a host-run client reaches the container stack with
  no further configuration.

### US-3 — Credential layout that resists leaks

> **As a** developer,
> **I want** it to be structurally difficult to commit a secret,
> **so that** a mistake is caught before it is pushed rather than after it is scraped.

**Acceptance criteria**

- **AC-3.1** `.gitignore` covers `.env.local`, `.env.*.local`, `backend/vendor/`,
  `frontend/node_modules/`, build output (`frontend/.expo/`, `frontend/dist/`, `backend/var/`) and
  local key material (`backend/config/jwt/*.pem`, `*.p8`, `*.key`).
- **AC-3.2** A secret-scanning pre-commit hook (gitleaks or equivalent) is committed to the repo
  with its configuration, is installed by a documented command, and blocks a commit containing a
  credential-shaped string.
- **AC-3.3** The same scanner runs as a CI job over the full history of the pushed branch, so a
  developer who bypasses the hook is still caught.
- **AC-3.4** A scan of the repository at merge time reports zero findings.
- **AC-3.5** No secret is present in any Docker image layer: no `ENV` or `ARG` carrying a
  credential, no `COPY` of an `.env.local`, and `.dockerignore` excludes environment files.

### US-4 — `.env.example` as the complete, obviously-fake contract

> **As a** developer configuring a fresh checkout,
> **I want** one file per side that lists every variable the application reads,
> **so that** I know exactly what to provide and can never mistake a placeholder for a real value.

**Acceptance criteria**

- **AC-4.1** `backend/.env.example` contains every backend variable in `docs/env-vars.md`: Core
  (`APP_ENV`, `APP_SECRET`, `DATABASE_URL`, `REDIS_URL`, `MESSENGER_TRANSPORT_DSN`,
  `CORS_ALLOW_ORIGIN`), Authentication (`JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`,
  `JWT_TTL`, `REFRESH_TOKEN_TTL`, `TOKEN_ENCRYPTION_KEY`), setlist.fm (`SETLISTFM_API_KEY`,
  `SETLISTFM_DAILY_BUDGET`, `SETLISTFM_RATE_PER_SECOND`, `SETLISTFM_CACHE_TTL`), streaming providers
  (Spotify, YouTube and the future Apple MusicKit variables) and backoffice (`ADMIN_PATH_PREFIX`,
  `ADMIN_TOTP_ISSUER`, `ADMIN_IP_ALLOWLIST`).
- **AC-4.2** `frontend/.env.example` contains `EXPO_PUBLIC_API_URL` and `EXPO_PUBLIC_ENV`, and
  carries a comment stating that anything prefixed `EXPO_PUBLIC_` ships inside the app bundle and is
  therefore public.
- **AC-4.3** Every value is obviously fake — `changeme`, `replace-me-...`, `xxxx...` — and no value
  could be mistaken for a working credential. Non-secret defaults documented in `docs/env-vars.md`
  (`SETLISTFM_DAILY_BUDGET=1440`, `SETLISTFM_RATE_PER_SECOND=2`, `YOUTUBE_DAILY_QUOTA_UNITS=10000`,
  `ADMIN_PATH_PREFIX=/admin`) carry their real defaults.
- **AC-4.4** Each variable is grouped under the same headings used in `docs/env-vars.md` and marked
  when it is a secret, so the two documents can be diffed by eye.
- **AC-4.5** A CI check fails if a variable listed in `docs/env-vars.md` is missing from the
  corresponding `.env.example` (a simple name-extraction diff is sufficient).

### US-5 — Hardened container images

> **As a** security-conscious engineer,
> **I want** the runtime containers to be minimal and non-root,
> **so that** a compromised process in the backend container is not a compromised host.

**Acceptance criteria**

- **AC-5.1** Backend `Dockerfile` under `docker/` is multi-stage: a build stage carrying toolchain
  and dependencies, and a slim runtime stage carrying only what runs.
- **AC-5.2** The runtime stage declares a dedicated non-root user and `USER` is set before
  `CMD`/`ENTRYPOINT`; `docker compose exec backend id` reports a non-zero UID.
- **AC-5.3** Files written by the container into bind-mounted project directories are owned by the
  developer's UID (build-arg UID/GID mapping), so no host-side `chown` workaround is ever needed.
- **AC-5.4** Base images are pinned to a specific tag and digest.
- **AC-5.5** PostgreSQL and Redis persist to **named volumes**, never to host bind mounts.
- **AC-5.6** No container publishes a port beyond those documented (`8000` backend, and database /
  Redis ports only if explicitly needed for local tooling).

### US-6 — CI that runs green from the first commit

> **As a** maintainer,
> **I want** a GitHub Actions pipeline in place before there is code to test,
> **so that** every later feature inherits a working quality gate instead of adding one late.

**Acceptance criteria**

- **AC-6.1** A workflow runs on push to any branch and on pull requests targeting `master`.
- **AC-6.2** Jobs exist for: `secret-scan`, `backend` (install → lint → test) and `frontend`
  (install → lint → test). Backend and frontend jobs may be near-empty stubs while the projects are
  empty, but must be wired so that adding real commands is a one-line change.
- **AC-6.3** A job validates that `compose.yaml` and the Dockerfiles build, and that the stack comes
  up healthy in CI.
- **AC-6.4** The pipeline is green on the feature branch before the PR is opened.
- **AC-6.5** No CI job calls a real external API (setlist.fm, Spotify, YouTube) — see
  [Decision D-2](#decisions).
- **AC-6.6** CI reads any value it needs from repository secrets; no credential appears in workflow
  YAML.

### US-7 — A README that gets a stranger running

> **As a** new contributor,
> **I want** the root `README.md` to take me from clone to running stack,
> **so that** I never have to ask a teammate for an undocumented step.

**Acceptance criteria**

- **AC-7.1** `README.md` covers: what Setlistify is (2–3 sentences), prerequisites with minimum
  versions (Docker Engine + Compose v2, Node LTS, npm/pnpm), clone → configure → up, the service URL
  table, how to start the Expo client on the host, and how to tear down.
- **AC-7.2** It links to `docs/architecture.md`, `docs/env-vars.md`, `docs/external-apis.md` and
  `docs/prompts/README.md`, and does **not** duplicate their content.
- **AC-7.3** It lists **no API endpoints** — the generated OpenAPI spec is the single source of
  truth (`CLAUDE.md`, API Contract).
- **AC-7.4** A person who has never seen the repository follows it end to end without a blocker.
  Verified by a clean-clone dry run recorded in the PR description.

### US-8 — The shape of production, without provisioning it

> **As a** developer,
> **I want** production configuration described but not built,
> **so that** later deployment work has a defined target and no accidental production surface exists now.

**Acceptance criteria**

- **AC-8.1** A short "Environments" section (root `README.md` or `docs/env-vars.md`, whichever
  avoids duplication) states that production values are injected at runtime by the PaaS secret
  store and that **no `.env` file is ever deployed**.
- **AC-8.2** It records that dev and production use **separate OAuth app registrations** per
  provider, with per-environment redirect URIs.
- **AC-8.3** No hosting account, DNS entry, PaaS project or production credential is created by this
  feature.

## Technical Approach

**Repository layout** (`docs/` already present):

```
setlistify-v2/
├─ backend/            .env.example, .gitignore entries — no application code yet
├─ frontend/           .env.example — no application code yet
├─ docker/             Dockerfile(s), entrypoint, container config
├─ docs/               existing
├─ .github/workflows/  ci.yml
├─ compose.yaml
├─ .gitleaks.toml      (or equivalent scanner config)
├─ .gitignore
└─ README.md
```

**Compose services**

| Service | Image / build | Volume | Healthcheck |
|---|---|---|---|
| `postgres` | official PostgreSQL, pinned | named volume `postgres_data` | `pg_isready` |
| `redis` | official Redis, pinned | named volume `redis_data` | `redis-cli ping` |
| `backend` | built from `docker/` | project bind mount for source | HTTP probe on the container port |

`backend` declares `depends_on` with `condition: service_healthy` for both `postgres` and `redis`,
so a cold `up` orders itself correctly.

**Pre-commit hook.** Committed under version control (e.g. `.githooks/` plus
`git config core.hooksPath`, or a `pre-commit` framework config), installed by a single documented
command, so the hook is reviewable rather than something each developer sets up their own way.

**Documentation updates in the same branch** (per `CLAUDE.md`, Documentation Update): root
`README.md` created; `docs/architecture.md` amended with the PHP runtime decision (D-1) and the CI
external-API decision (D-2); `docs/env-vars.md` only if a variable is added.

### Decisions

**D-1 — PHP runtime: FrankenPHP.**
The stack runs as a single `backend` container built on FrankenPHP rather than a paired
nginx + PHP-FPM setup. Rationale: one container instead of two removes an inter-container
networking surface and a second config file from local dev; it maps cleanly onto the managed-PaaS
production target (`docs/architecture.md` §1), which prefers one process on one port; and it is the
runtime Symfony itself now leads with. Worker mode is **not** enabled at MVP — classic
request-per-process first, worker mode revisited only if measurements justify it, since worker mode
changes application-state assumptions and there is no application yet to measure. The cost is lower
team familiarity with FrankenPHP's Caddy layer; the mitigation is that the alternative remains
mechanical to adopt (swap the runtime stage, add an nginx service) because nothing above the
container depends on the choice. To be recorded in `docs/architecture.md`.

**D-2 — CI runs no integration tests against real external APIs.**
CI exercises only local services and recorded fixtures. Rationale: setlist.fm's standard key allows
**1,440 requests per day for the entire application**, not per user or per environment
(`CLAUDE.md`, `docs/env-vars.md`) — a CI job running on every push could consume the production
budget and take the product down. Provider quotas (YouTube units, Spotify rate limits) carry the
same hazard. Contract verification against live providers, when needed, is a deliberate manual or
scheduled run using dedicated test credentials, never a per-push job. To be recorded in
`docs/architecture.md`.

**D-3 — The frontend is not containerized.**
Deliberate, not an omission: Expo's native tooling (Metro bundler, device/simulator access, QR
pairing over the LAN) works poorly through container networking. Documented explicitly so it is not
"fixed" by a later contributor.

### Suggested implementation order

| Step | Work | Notes |
|---|---|---|
| 1 | `.gitignore`, `.dockerignore`, secret scanner + pre-commit hook | Guardrails before any config file exists |
| 2 | Directory skeleton, both `.env.example` files | Derived directly from `docs/env-vars.md` |
| 3 | `docker/` Dockerfiles (multi-stage, non-root) | |
| 4 | `compose.yaml` with healthchecks and named volumes | |
| 5 | CI workflow | Verify green |
| 6 | Root `README.md` + `docs/architecture.md` amendments | |
| 7 | Clean-clone dry run | Evidence for AC-1.2 and AC-7.4 |

## Out of Scope

- **Symfony application code** — the backend container starts and answers a health probe; the
  Symfony skeleton, bundles, entities and API resources are prompt 01.
- **Expo application code** — `frontend/` holds only `.env.example`; the client is prompt 03.
- **Authentication, JWT keypair generation, user accounts** — prompt 04. This feature only reserves
  the variable names and gitignores the key paths.
- **setlist.fm and streaming provider integration** — prompts 09–11. Only their env-var names appear
  here.
- **Production deployment.** The *shape* of production configuration is documented (US-8); nothing
  is provisioned — no PaaS project, no DNS, no TLS, no production secret store entries.
- **CI test steps for code that does not exist.** Lint and test jobs are wired but may be stubs.
- **Containerizing the frontend** (D-3).
- **Database schema or migrations** — PostgreSQL comes up empty.
- **Observability** — logging, metrics and error tracking are later work.
- **A staging environment.**

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| `docs/architecture.md` §1 stack decisions are settled | Documented, status **decided** (2026-08-21) | Met |
| `docs/env-vars.md` variable inventory is complete | Documented | Met |
| Docker Engine + Compose v2 available on developer machines | Developer | Assumed |
| Repository hosted on GitHub with Actions enabled | Maintainer | **To confirm** |
| Permission to add repository secrets for CI | Maintainer | **To confirm** |

**Depended on by:** every subsequent prompt (01–26). Nothing else in the backlog can start until
this merges.

**Assumptions** *(flagged as assumptions, not verified facts)*

- The repository is hosted on GitHub, so GitHub Actions is the CI platform (implied by the prompt).
- Developers run Linux or macOS. Windows/WSL2 is not a verified target; if it is required, the UID
  mapping in AC-5.3 needs revisiting.
- `master` is the integration branch, per `CLAUDE.md`.

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **FrankenPHP unfamiliarity** (D-1) — Caddy-layer debugging is new to the team | Medium | Nothing above the container depends on the runtime; swapping to nginx + PHP-FPM is a runtime-stage change. Record the decision and its reversal path in `docs/architecture.md`. |
| R-2 | **CI burning the setlist.fm daily budget** | High — a chatty CI job can exhaust the *application-wide* 1,440/day allowance and break production | D-2: no live external calls in CI. Any future live check uses dedicated test credentials and runs on a schedule, never per push. |
| R-3 | **A developer bypasses the pre-commit hook** (`--no-verify`, or never installs it) | High | AC-3.3 duplicates the scan in CI, which cannot be bypassed. README makes hook installation part of the setup path. |
| R-4 | **Secret scanner false positives** blocking legitimate commits | Low–Medium | Tune an allowlist in the scanner config for `.env.example` placeholders and fixture data; document how to add an entry. |
| R-5 | **Bind-mount file ownership** — container-written files owned by root on the host | Medium — breaks local editing, and Execution Policy forbids working around it outside the tree | AC-5.3 UID/GID build args. If unresolvable, stop and report rather than working around it (`CLAUDE.md`, Execution Policy). |
| R-6 | **`.env.example` drifts from `docs/env-vars.md`** as later features add variables | Medium — a missing variable surfaces as a confusing runtime failure | AC-4.5 CI drift check, plus the existing "Checklist for adding a variable" in `docs/env-vars.md`. |
| R-7 | **Slow cold start in CI** (image builds on every push) | Low | Layer caching / GitHub Actions cache; revisit if the compose job exceeds a few minutes. |
| R-8 | **Expo/host ↔ container URL mismatch** on physical devices — `localhost` resolves to the device | Medium | Document the LAN-IP override for `EXPO_PUBLIC_API_URL` when testing on a real handset. |

---

## Review

**Approved by the user on 2026-08-21**, including both decisions:

1. **D-1 — FrankenPHP over nginx + PHP-FPM.** Accepted. Record in `docs/architecture.md` as part of
   the implementation branch.
2. **D-2 — No live external API calls in CI.** Accepted. Record in `docs/architecture.md`.

**Still open — confirm during implementation, do not assume:** GitHub Actions availability for this
repository, and permission to create repository secrets for CI (see Dependencies). If either turns
out to be false, stop and report rather than substituting another CI platform.

Next: branch `feature/monorepo-and-environments` from `master`, implemented by
`devops-security-engineer`, tests included in the same step, per the mandatory workflow in
`CLAUDE.md`.
