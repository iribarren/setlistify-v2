# 00 — Monorepo and environments

**Command:** `/feature monorepo-and-environments` · **Agent:** `devops-security-engineer` · **Depends on:** —

## Goal
A developer clones the repository, runs one command, and has PostgreSQL, Redis and a responding
backend container running locally — with a credential layout that makes it structurally hard to leak
a secret or to point a development build at production data.

## Context
Greenfield. Nothing exists but documentation. Read `docs/architecture.md` §1 for the stack and
`docs/env-vars.md` in full — this prompt implements the credential-separation rules that document
describes, and every later prompt assumes they are in place.

## Scope
- Monorepo layout: `backend/`, `frontend/`, `docker/`, with `docs/` already present.
- `compose.yaml` bringing up `postgres`, `redis` and `backend` (PHP 8.4-FPM + nginx, or FrankenPHP).
  Named volumes for data. The frontend runs on the host via Expo CLI and is not containerized.
- Dockerfiles under `docker/`, multi-stage, non-root runtime user, no secrets baked into any layer.
- `.env.example` for backend and frontend, listing every variable from `docs/env-vars.md` with
  obviously-fake placeholders.
- `.gitignore` covering `.env.local`, `.env.*.local`, `vendor/`, `node_modules/`, build output, and
  any local key material.
- A secret-scanning pre-commit hook (gitleaks or equivalent) wired into the repo.
- CI skeleton (GitHub Actions): install, lint, test, secret scan. Jobs may be near-empty stubs while
  the projects are empty, but the pipeline must run green.
- Root `README.md`: what Setlistify is, prerequisites, how to bring it up, where the docs are.

## Out of scope
- Any Symfony or Expo application code — prompts 01 and 03.
- Production deployment. Define the *shape* of production configuration (secrets injected at runtime,
  no `.env` deployed) without provisioning anything.
- CI test steps for code that does not exist yet.

## Acceptance criteria
- [ ] `docker compose up -d` from a clean clone brings up postgres, redis and backend healthy.
- [ ] Every service defines a healthcheck; `docker compose ps` shows healthy, not just running.
- [ ] No real credential exists anywhere in the repo. The secret scanner runs on commit and passes.
- [ ] `.env.example` covers every variable in `docs/env-vars.md`, and nothing in it is a real value.
- [ ] Containers run as a non-root user.
- [ ] CI runs on push and is green.
- [ ] `README.md` gets a new developer from clone to running stack with no undocumented steps.
- [ ] No command in the setup path runs outside the project tree (see Execution Policy in `CLAUDE.md`).

## Risks & open questions
- **PHP runtime choice**: FrankenPHP is simpler (one container, worker mode) but less familiar than
  nginx + PHP-FPM. Pick one and record why in `docs/architecture.md`.
- The frontend staying un-containerized is deliberate — Expo's native tooling fights containers. Make
  sure the compose docs are explicit that `npx expo start` runs on the host.
- Decide now whether CI runs integration tests against real external APIs. If yes, dedicated test
  credentials only, and mind setlist.fm's 1,440/day budget — a chatty CI job could eat the
  application's entire daily allowance.
