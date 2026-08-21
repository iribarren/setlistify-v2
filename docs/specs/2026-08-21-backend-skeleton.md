# Backend Skeleton

| | |
|---|---|
| **Spec ID** | `2026-08-21-backend-skeleton` |
| **Backlog prompt** | `docs/prompts/01-backend-skeleton.md` |
| **Command** | `/feature backend-skeleton` |
| **Primary agent** | `backend-engineer` |
| **Branch** | `feature/backend-skeleton` |
| **Depends on** | `00` — monorepo and environments (merged, `f7cd891`) |
| **Status** | **Draft** 2026-08-21 — awaiting user approval |

---

## Overview

Prompt 00 delivered infrastructure: `docker compose up -d` brings up PostgreSQL, Redis and a
FrankenPHP `backend` container that all report *healthy*. But the container serves a placeholder —
`backend/public/index.php` is 20 lines of hand-written PHP that echoes a JSON literal. There is no
`composer.json`, no `src/`, no framework.

This feature replaces that placeholder with a real Symfony application: the first running slice of
the actual backend, plus the conventions and tooling that every later backend prompt (04 auth,
05 concert domain, 08 backoffice, 09 setlist.fm, 10–11 streaming) will build on without
re-litigating them.

Three outcomes define it:

1. **A framework that serves.** Symfony 8.1 on PHP 8.4, API Platform 4.3, Doctrine ORM wired to the
   compose PostgreSQL, running in the *existing* container — no new service, no host-side PHP.
2. **A contract that generates.** `/api/docs` renders and the OpenAPI JSON is fetchable. Prompt 03
   generates `frontend/api/` from exactly this document, so the endpoint that proves it works
   (`GET /api/health`) is itself an API Platform resource rather than a bare controller route.
3. **Guardrails that are structural, not procedural.** Migrations instead of `schema:update`,
   PHPUnit against a separate test database, PHPStan at a high level, PHP-CS-Fixer, RFC 7807 error
   shapes, non-wildcard CORS and security headers — all enforced by the CI wired in prompt 00, which
   currently runs stubs.

This feature ships **no domain**. One endpoint, one contract, one set of conventions.

## Goals

| Goal | Success looks like |
|---|---|
| A real application in the existing container | `docker compose up -d` unchanged; the backend answers `GET /api/health` from Symfony, and `backend/public/index.php` is Symfony's front controller |
| A generated, authoritative contract | `/api/docs` renders; the OpenAPI JSON is fetchable and contains `/api/health` — prompt 03 needs no hand-written types |
| Schema changes are always reviewable | Every schema change is a versioned migration file in git; `doctrine:schema:update` is never the mechanism |
| Conventions visible, not just described | A developer opening `backend/src/` can see where an entity, a repository, a resource, a service and a message handler go, without reading `docs/architecture.md` first |
| Failure is well-shaped | Every error response is RFC 7807; a `prod` error never leaks a stack trace, a file path or a SQL string |
| CI stops being a stub | The `backend` CI job installs, lints, statically analyses, migrates and tests — and is green |
| Nothing runs outside the project | Every documented command is `docker compose exec backend …` (Execution Policy, `CLAUDE.md`) |

## User Stories

### US-1 — A Symfony application inside the existing container

> **As a** backend developer,
> **I want** a Symfony 8.1 application that boots inside the `backend` container from prompt 00,
> **so that** I can add features without first assembling a runtime, and without running PHP on my host.

**Acceptance criteria**

- **AC-1.1** `backend/composer.json` and `backend/composer.lock` exist and pin PHP `>=8.4`,
  Symfony `8.1.*` and API Platform `4.3.*`. `composer.lock` is committed.
- **AC-1.2** `backend/public/index.php` is Symfony's front controller; the prompt-00 placeholder file
  is gone.
- **AC-1.3** `docker compose up -d` from a clean clone, followed by the documented install and
  migrate commands, yields a `backend` service reporting `healthy`.
- **AC-1.4** Every command in the documentation runs inside the container
  (`docker compose exec backend …`). No documented step installs PHP, Composer or an extension on
  the host.
- **AC-1.5** `docker/backend/Dockerfile` installs the PHP extensions the application actually needs
  (`pdo_pgsql`, `intl`, `opcache`, `zip`, plus the Redis client mechanism chosen in D-9). The build
  stage's `composer install` runs for real now that `composer.json` exists.
- **AC-1.6** The image builds with `--no-dev` for runtime while the developer's container still has
  the dev toolchain available (PHPUnit, PHPStan, CS-Fixer) — see D-9.
- **AC-1.7** `backend/var/` and `backend/vendor/` are gitignored and writable by the container's
  non-root `appuser` through the bind mount, with no host-side `chown` workaround. If they are not,
  implementation **stops and reports** rather than working around it (Execution Policy).

### US-2 — A health endpoint that tells the truth

> **As an** operator (and as CI, and as the container's own healthcheck),
> **I want** one endpoint that reports whether the app and its dependencies are actually usable,
> **so that** "the container is up" and "the application works" are not the same claim.

**Acceptance criteria**

- **AC-2.1** `GET /api/health` returns `200` with a JSON body reporting overall status plus a
  per-dependency status for `database` and `redis`.
- **AC-2.2** The check is a real round-trip per dependency — a trivial query against PostgreSQL and a
  `PING`-equivalent against Redis — not a configuration read or a "the DSN is set" assertion.
- **AC-2.3** With a dependency genuinely down (`docker compose stop redis`), the endpoint returns
  `503`, names the failing dependency, and still returns the status of the healthy ones. It does not
  hang and does not 500.
- **AC-2.4** Each dependency check has a short timeout (target ≤ 2s) so a wedged dependency cannot
  make the health endpoint itself unresponsive.
- **AC-2.5** The response contains no credential, no DSN, no host, no port and no driver exception
  message — status and a safe label only.
- **AC-2.6** The endpoint is public: it requires no authentication (there is none yet — prompt 04
  must keep it public when it adds the firewall).
- **AC-2.7** The `backend` healthcheck in `compose.yaml` and the CI probe in `.github/workflows/ci.yml`
  both target `/api/health` instead of `/`. Both are updated in this branch.

### US-3 — An OpenAPI document the client can be generated from

> **As a** frontend developer (prompt 03),
> **I want** the backend to publish a generated OpenAPI document,
> **so that** I generate `frontend/api/` from it and a breaking API change becomes a compile error rather than a runtime surprise.

**Acceptance criteria**

- **AC-3.1** `http://localhost:8000/api/docs` renders API Platform's documentation UI.
- **AC-3.2** The OpenAPI document is fetchable as JSON (e.g. `GET /api/docs.jsonopenapi`, and/or via
  `bin/console api:openapi:export`), and is valid OpenAPI 3.1.
- **AC-3.3** `GET /api/health` appears in that document with its response schema and both status
  codes (`200`, `503`) described.
- **AC-3.4** The health endpoint is implemented as an API Platform resource (a DTO plus a state
  provider), **not** as a hand-rolled `#[Route]` controller — so it cannot drift out of the spec
  (see D-6).
- **AC-3.5** `bin/console api:openapi:export` succeeds inside the container and is documented as the
  command prompt 03 will consume.
- **AC-3.6** No `/admin` route appears in the document (there is no backoffice yet; the exclusion
  must hold when prompt 08 adds one).

### US-4 — Schema changes through migrations only

> **As a** developer,
> **I want** every schema change to be a reviewable, versioned migration,
> **so that** production schema never depends on someone remembering to run the right command, and a schema change is visible in a diff.

**Acceptance criteria**

- **AC-4.1** Doctrine ORM and `doctrine/doctrine-migrations-bundle` are installed and configured
  against `DATABASE_URL` from the environment — no DSN is hard-coded in `config/`.
- **AC-4.2** `docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction`
  runs clean against an empty database and is idempotent (a second run is a no-op).
- **AC-4.3** `backend/migrations/` exists and is committed, containing at minimum the baseline
  produced by this feature.
- **AC-4.4** `doctrine:schema:update` is never used in any documented command, script, CI step or
  test bootstrap. The prohibition is written into the backend README.
- **AC-4.5** The Doctrine connection uses the pinned `serverVersion` from `DATABASE_URL` so the
  application does not query the server at boot to discover it.
- **AC-4.6** No entity is defined by this feature (US-5, D-4) — the baseline migration may therefore
  contain only Doctrine's own bookkeeping. That is the expected outcome, not a failure.

### US-5 — Layering that is visible in the tree

> **As a** developer (or agent) writing the next backend feature,
> **I want** the layout from `docs/architecture.md` §3 to already exist with the rules stated in place,
> **so that** I do not have to decide where code goes, and reviewers do not have to re-argue it.

**Acceptance criteria**

- **AC-5.1** `backend/src/` contains the full §3 tree: `Entity/`, `Repository/`, `ApiResource/`,
  `Controller/Admin/`, `Service/{Setlist,Streaming,Provider,Matching,Security}/`, `MessageHandler/`,
  `Message/`.
- **AC-5.2** Each directory carries a short `README.md` stating its rule verbatim from §3 — notably
  "only `Repository/` touches Doctrine's query layer", "only `Service/Streaming/<Provider>/` knows a
  provider exists", and "controllers and API resources contain no business logic".
- **AC-5.3** The health slice is implemented as a real, working example across the layers it
  legitimately touches: an `ApiResource/` DTO + state provider, and a `Service/` health checker with
  one small class per dependency.
- **AC-5.4** No placeholder domain class is invented to fill `Entity/` or `Repository/` (D-4) — those
  layers are documented by their README and filled by prompt 05.
- **AC-5.5** PSR-4 autoloading maps `App\` to `backend/src/`, and the namespace of every created
  class matches its directory.
- **AC-5.6** `docs/architecture.md` §1 gains a short note on the Symfony 8.1 → 8.4 LTS upgrade path
  (R-3), since the prompt flags it explicitly.

### US-6 — Errors that are shaped, and never leak

> **As a** client developer,
> **I want** every error to arrive in one predictable machine-readable shape,
> **so that** I write one error handler; **and as** the person responsible for security, I want production errors to reveal nothing about the internals.

**Acceptance criteria**

- **AC-6.1** Error responses use RFC 7807 problem details (`application/problem+json`) with `type`,
  `title`, `status` and `detail`, configured globally rather than per-endpoint.
- **AC-6.2** A `404` (unknown route under `/api`), a `405` (wrong method) and a `500` (forced
  exception) all return the problem-details shape, not HTML and not a bare string.
- **AC-6.3** In `APP_ENV=prod`, no response body contains a stack trace, an exception class name, a
  file path, a SQL fragment or a framework internal. A test asserts this against a deliberately
  thrown exception.
- **AC-6.4** In `APP_ENV=dev`, richer debug output remains available — the restriction is
  environment-driven, not a permanent loss of developer ergonomics.
- **AC-6.5** Unhandled exceptions are logged server-side with enough context to debug, even when the
  response says almost nothing.

### US-7 — A test suite that runs against a real database

> **As a** developer,
> **I want** PHPUnit configured with a dedicated `test` environment and its own database,
> **so that** tests exercise the real integration path and never touch my development data.

**Acceptance criteria**

- **AC-7.1** `docker compose exec backend vendor/bin/phpunit` is green.
- **AC-7.2** A functional test boots the kernel, calls `GET /api/health` and asserts `200`, the
  content type, and the presence of the `database` and `redis` entries.
- **AC-7.3** A test asserts the `503` path with a failing dependency (via a test double at the
  dependency-check seam, so the suite does not require stopping a container).
- **AC-7.4** A test asserts the RFC 7807 shape (AC-6.2) and the no-leak rule (AC-6.3).
- **AC-7.5** The `test` environment uses a **separate database** (e.g. a `_test` suffix on the same
  compose PostgreSQL instance). Running the suite never mutates the development database.
- **AC-7.6** The test database is created and migrated by a documented, repeatable command, using
  migrations — not `schema:update` (AC-4.4).
- **AC-7.7** Test configuration honours "rule zero": no committed `.env` or `.env.test` carrying
  values (D-5). Test overrides live in `phpunit.xml.dist`.
- **AC-7.8** Tests make no call to setlist.fm, Spotify or YouTube (D-2, `docs/architecture.md`).

### US-8 — Static analysis and formatting, enforced by CI

> **As a** maintainer,
> **I want** PHPStan and PHP-CS-Fixer running from the first commit at a level chosen now,
> **so that** quality is a property of the pipeline rather than of whoever is reviewing.

**Acceptance criteria**

- **AC-8.1** PHPStan is installed and configured at **level 9** (D-8) with the Symfony and Doctrine
  extensions; `vendor/bin/phpstan analyse` passes with **zero errors and an empty baseline**.
- **AC-8.2** PHP-CS-Fixer is installed with a committed rule set; `--dry-run --diff` passes on the
  whole of `backend/src/` and `backend/tests/`.
- **AC-8.3** `composer lint` and `composer test` scripts exist in `backend/composer.json` — the CI
  workflow from prompt 00 already calls exactly these names.
- **AC-8.4** The `backend` CI job stops being a stub: it installs dependencies, runs lint (CS-Fixer +
  PHPStan), runs migrations against a real PostgreSQL, runs PHPUnit, and is green.
- **AC-8.5** CI runs the backend checks on the runner against GitHub Actions `services:` containers
  for PostgreSQL and Redis (D-7), pinned to the same versions as `compose.yaml`. A CI failure is
  reproduced locally by running the same commands inside the compose stack, even though the CI
  environment itself is not the compose stack.
- **AC-8.6** CI makes no live call to any external provider API (D-2).
- **AC-8.7** A deliberate style violation or a deliberate type error fails CI — verified once during
  implementation, not assumed.

### US-9 — CORS and security headers, correct by default

> **As** the person responsible for security,
> **I want** the browser-facing defaults to be safe before the first real endpoint exists,
> **so that** we never ship a permissive default that nobody remembers to tighten.

**Acceptance criteria**

- **AC-9.1** CORS is configured from the `CORS_ALLOW_ORIGIN` environment variable (already present in
  `backend/.env.example` as a regex).
- **AC-9.2** The allowed origin is **never** `*` — in any environment, in any committed config file,
  including the fallback used when the variable is unset. An unset variable results in a restrictive
  default or a clear boot failure, never a wildcard.
- **AC-9.3** A preflight `OPTIONS` from an allowed origin succeeds; one from a disallowed origin does
  not receive permissive CORS headers. Both are covered by a test.
- **AC-9.4** Every response carries security headers: `X-Content-Type-Options: nosniff`,
  `X-Frame-Options` (or an equivalent CSP `frame-ancestors`), `Referrer-Policy` and a
  `Content-Security-Policy` appropriate for a JSON API. `Strict-Transport-Security` is set in `prod`.
- **AC-9.5** The header set does not break `/api/docs`, which renders its own UI assets — verified by
  loading the page, and by a test that asserts headers on both an API response and the docs page.
- **AC-9.6** Headers are applied globally (an event subscriber or equivalent), so a future endpoint
  cannot forget them.
- **AC-9.7** No response exposes the framework or server version (`X-Powered-By`, `Server` banner
  detail).

## Technical Approach

**Sub-project:** `backend/` only. `frontend/` is untouched. `docker/backend/Dockerfile`,
`compose.yaml` and `.github/workflows/ci.yml` are amended, not rewritten.

**Starting state (verified 2026-08-21):**

| Path | Current state |
|---|---|
| `backend/` | `public/index.php` (placeholder), `.env.example`, `.dockerignore` — no `composer.json`, no `src/` |
| `docker/backend/Dockerfile` | FrankenPHP `1-php8.4`, non-root `appuser`, build stage already tolerates a missing `composer.json`; **no PHP extensions installed beyond the base image**; `HEALTHCHECK` targets `/` |
| `compose.yaml` | `postgres` 17.6, `redis` 7.4 (neither publishes a host port), `backend` on `8000:8080`, bind-mounts `./backend:/app`, healthcheck targets `/` |
| `.github/workflows/ci.yml` | `backend` job is a stub guarded by `if [ -f composer.json ]`, with **no database or Redis service attached** |
| `backend/.env.example` | Already declares `APP_ENV`, `APP_SECRET`, `DATABASE_URL`, `REDIS_URL`, `MESSENGER_TRANSPORT_DSN`, `CORS_ALLOW_ORIGIN` |

**Shape of the work:**

```
backend/
├─ composer.json / composer.lock      Symfony 8.1, API Platform 4.3, Doctrine, dev tooling
├─ public/index.php                   Symfony front controller (replaces the placeholder)
├─ config/                            framework, api_platform, doctrine, migrations, nelmio_cors, packages/prod
├─ migrations/                        versioned, committed
├─ src/
│  ├─ ApiResource/HealthStatus.php    the DTO in the OpenAPI document
│  ├─ State/HealthStateProvider.php   API Platform state provider (no business logic)
│  ├─ Service/Health/                 HealthChecker + DatabaseCheck + RedisCheck
│  ├─ EventSubscriber/                security headers
│  └─ …                               the rest of the §3 tree, each with a README.md
├─ tests/                             functional: health 200/503, problem+json, no-leak, CORS, headers
├─ phpunit.xml.dist                   test env + test DATABASE_URL override (D-5)
├─ phpstan.neon.dist                  level 9, no baseline
└─ .php-cs-fixer.dist.php
```

### Decisions

Numbered from **D-4** onward; `D-1`–`D-3` are the project-wide decisions already recorded in
`docs/architecture.md`.

**D-4 — The layer tree is documented by READMEs; only the health slice ships real code.**
The prompt asks for "a representative example in each" layer, but this feature must not define a
domain entity (out of scope, prompt 05). Inventing a throwaway `Ping` entity to fill `Entity/` and
`Repository/` would create code whose only purpose is deletion, and would seed a migration for a
table nobody wants. Instead: every §3 directory exists with a `README.md` stating its rule (which
also solves git's inability to track an empty directory), and the health slice provides a genuine
worked example of `ApiResource/` → state provider → `Service/`. Prompt 05 fills `Entity/` and
`Repository/` with real domain code.

**D-5 — No `.env` or `.env.test` is committed; test configuration lives in `phpunit.xml.dist`.**
The Symfony skeleton normally commits `.env` and `.env.test`. `docs/env-vars.md` rule zero says
`.env.example` is the *only* environment file that ever enters git, and `scripts/check-env-vars-drift.sh`
enforces `.env.example` against the documented table. Committing more env files would either break
that rule or split the contract across three files. The application therefore reads its configuration
from the process environment (compose `env_file: backend/.env.local`, PaaS secret store in
production), and the `test` environment's overrides — `APP_ENV=test` and the `_test` database URL —
are declared in `phpunit.xml.dist`, which is configuration, not a credential file.

**D-6 — Health is an API Platform resource with a state provider, not a controller route.**
`CLAUDE.md`'s API Contract section makes the generated OpenAPI document the single source of truth,
and prompt 03 generates the TypeScript client from it. A `#[Route]` controller would serve traffic
without appearing in that document, establishing exactly the drift pattern the contract forbids —
in the very first endpoint, which every later prompt will copy. The `503` is returned by having the
provider surface an unhealthy state that maps to the status code, with both codes declared on the
operation so the generated client knows about them.

**D-7 — Backend CI runs on the runner with GitHub Actions service containers.**
The stub `backend` job runs on the runner with `setup-php` and has no PostgreSQL or Redis, so it
cannot run migrations or the functional tests as written. The job gains `services:` entries for
`postgres:17.6` and `redis:7.4`, matching the versions pinned in `compose.yaml`, with PHP set up via
`setup-php` at the same 8.4 floor and the same extensions installed in `docker/backend/Dockerfile`
(D-9) declared explicitly so the two environments stay in lockstep. This keeps the job fast (no image
build per push, standard `actions/cache` for Composer) and matches common GitHub Actions practice for
PHP projects. The tradeoff: the runner's environment is a parallel definition of the stack rather than
the one used in `compose.yaml`, so the two must be kept in sync by hand — flagged as R-6 below, with
a periodic check that the versions and extensions have not drifted apart.

**D-8 — PHPStan level 9, no baseline.**
The prompt requires "level 8 or higher" and notes that levels are never raised later. Level 9 adds
`mixed`-type strictness, which is cheap to satisfy in a codebase of this size and expensive to
retrofit once integration code exists. An empty baseline is part of the decision: a baseline created
now would silently become permanent. If level 9 proves unworkable against API Platform's or
Doctrine's stubs during implementation, drop to 8 and **record why in this spec** rather than adding
a baseline.

**D-9 — The Dockerfile gains PHP extensions and a dev/runtime split.**
The base FrankenPHP image does not ship `pdo_pgsql`, `intl` or a Redis client, so a Symfony app
talking to PostgreSQL and Redis cannot boot on the current image. The Dockerfile adds them via the
image's `install-php-extensions` helper. Redis access may use the `redis` PHP extension or a pure-PHP
client (Predis); the implementer picks one and records it — the extension is preferred for parity
with the Messenger transport later. Runtime keeps `--no-dev`; the developer's container needs the
dev toolchain, so either the compose service targets the build stage or the documented workflow runs
`composer install` inside the running container against the bind mount. Whichever is chosen must
keep AC-1.7 (no root-owned files on the host) true.

### Suggested implementation order

1. Dockerfile extensions + dev toolchain availability (D-9) — nothing else can run until PHP can
   reach PostgreSQL.
2. Symfony 8.1 skeleton, front controller, `composer.json` scripts (`lint`, `test`).
3. Doctrine + migrations against the compose PostgreSQL; verify AC-4.2 on an empty database.
4. API Platform + `/api/docs`; the `§3` directory tree with READMEs.
5. The health slice: service checks → state provider → resource; then `compose.yaml` and CI probe
   retargeting (AC-2.7).
6. RFC 7807, security headers, CORS.
7. PHPUnit + `test` environment + the test database; the tests for 5 and 6.
8. PHPStan level 9 + CS-Fixer; then the CI job (D-7, `setup-php` + `services:` containers) and a
   deliberate-failure check (AC-8.7).
9. Documentation pass: backend README, `docs/architecture.md` LTS note, `/doc-check`.

## Out of Scope

- **Authentication and authorization** — JWT, users, firewalls, refresh tokens: prompt 04. The
  health endpoint is public and must stay public.
- **Any domain entity** — `Concert`, `Band`, `Setlist`, `Song`, `Playlist`, `ProviderSetting`:
  prompt 05 onward. `Entity/` and `Repository/` ship documented but empty (D-4).
- **The backoffice / EasyAdmin** — prompt 08. `Controller/Admin/` exists as a directory only.
- **setlist.fm and streaming provider integration** — prompts 09–11, 18. `Service/Setlist/`,
  `Service/Streaming/`, `Service/Provider/` and `Service/Matching/` are empty documented directories;
  `StreamingProviderInterface` is **not** written here.
- **Messenger transport configuration and async jobs** — `MESSENGER_TRANSPORT_DSN` already exists as a
  variable, but no transport, message or handler is configured.
- **Redis as a cache tier** — this feature only *pings* Redis for the health check. The three-tier
  setlist cache (`docs/architecture.md` §5) is prompt 09.
- **Token encryption / the libsodium Doctrine type** — prompt 10.
- **Rate limiting** — prompt 09 (setlist.fm) and prompt 04 (auth).
- **Frontend client generation** — prompt 03 consumes the OpenAPI document produced here; it is not
  generated in this branch.
- **Observability** — structured logging beyond AC-6.5, metrics, error tracking.
- **Production deployment** of the application.
- **New environment variables.** If implementation finds one is genuinely needed, it must be added to
  both `docs/env-vars.md` and `backend/.env.example` in the same branch, or the drift check fails.

## Dependencies

**Must be true before implementation begins**

| Dependency | Owner | Status |
|---|---|---|
| Prompt 00 merged — compose stack, Dockerfile, CI, `.env.example` | `devops-security-engineer` | **Met** (`f7cd891`) |
| `docs/architecture.md` §3 layering is settled | Documented, status **decided** | **Met** |
| `docs/env-vars.md` declares `DATABASE_URL`, `REDIS_URL`, `CORS_ALLOW_ORIGIN`, `APP_ENV`, `APP_SECRET` | Documented | **Met** |
| Symfony 8.1 and API Platform 4.3 are released and mutually compatible on PHP 8.4 | Upstream | **To verify at install time** — see R-1 |
| FrankenPHP `1-php8.4` can install `pdo_pgsql`, `intl`, `redis` | Upstream image | **To verify** (D-9) |
| Decisions D-1 (FrankenPHP) and D-2 (no live APIs in CI) recorded in `docs/architecture.md` | Prompt 00 branch | **Met** |
| Outbound network access in the container for `composer install` | Developer / CI | Assumed |

**Depended on by**

- **Prompt 03 (frontend skeleton)** — generates `frontend/api/` from the OpenAPI document produced
  here. This is a hard blocker.
- **Prompt 04 (auth)** — adds a firewall to this skeleton.
- **Prompt 05 onward** — every backend feature builds on this layering, migration workflow and test
  setup.

**Assumptions** *(labelled as assumptions, not verified facts)*

- The `test` database can live on the same compose PostgreSQL instance as development, distinguished
  by name. If parallel test runs later make this contentious, a separate service is a small change.
- `docker compose exec backend composer install` writing into the bind-mounted `./backend` produces
  host-owned files, given the UID/GID build args from prompt 00. If not, AC-1.7 applies: stop and
  report.
- Symfony 8.1's recipes/flex behave as documented for a non-standard container layout.

## Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| R-1 | **Version-floor availability** — Symfony 8.1 + API Platform 4.3 + EasyAdmin-compatible releases may not all be installable together on PHP 8.4 at implementation time | High — blocks the whole backlog | Verify at `composer require` time, before writing code. If a constraint cannot be satisfied, **stop and report** with the actual resolver output; do not silently drop to an older major, which would invalidate `docs/architecture.md` §1. |
| R-2 | **Fighting API Platform instead of embracing it** — the health endpoint is awkward as a resource, so it becomes a plain controller "just this once" | High — the first endpoint sets the pattern; a controller that bypasses the resource layer drifts out of the OpenAPI document and silently breaks the generated client contract (`CLAUDE.md`, API Contract) | D-6 makes it a resource with a state provider. AC-3.3 asserts it appears in the document, so the drift is caught by a test rather than by a frontend developer in prompt 03. |
| R-3 | **Symfony 8.1 is not LTS** (8.4 will be); the upgrade path is discovered under pressure | Medium | AC-5.6 records the upgrade path in `docs/architecture.md` §1 now, alongside the existing version-floor note. |
| R-4 | **PHPStan level chosen too low, or a baseline created** — raising a level across an existing codebase never happens | Medium, compounding | D-8: level 9, empty baseline, and a written rule that any reduction is recorded in this spec with its reason. |
| R-5 | **Missing PHP extensions surface late** — the app boots but cannot reach PostgreSQL or Redis | Medium | D-9 makes extensions step 1 of the implementation order, before any application code. |
| R-6 | **CI job cannot run migrations or functional tests** — the current stub has no database or Redis; **and** the runner environment (D-7) is a second definition of the stack that can drift from `compose.yaml`/the Dockerfile over time | High — CI would go green while testing nothing, or silently diverge from what actually ships | D-7 adds `services:` containers pinned to the same PostgreSQL/Redis versions as `compose.yaml`, with PHP extensions declared explicitly in the workflow to mirror D-9. AC-8.7 verifies CI actually fails on a real defect. Revisit if the two definitions drift. |
| R-7 | **Committed `.env` files break rule zero** — the Symfony skeleton adds them by default | Medium — a security-posture regression and a drift-check failure | D-5. Explicitly check `git status` for `.env` / `.env.test` before committing; the pre-commit gitleaks hook is a second net. |
| R-8 | **Security headers break `/api/docs`**, which serves its own UI assets, and the CSP is then loosened globally to fix it | Medium | AC-9.5 tests both surfaces. If a relaxation is needed, scope it to the docs route only, never to the API. |
| R-9 | **The health endpoint becomes a slow dependency of the container healthcheck** — a wedged Redis makes the container flap | Low–Medium | AC-2.4 per-check timeouts. If liveness and readiness need separating later, that is a small follow-up, not a redesign. |
| R-10 | **CI runtime grows** now that the backend job builds an image and runs a real suite | Low | Accepted; layer caching first, revisit only if the pipeline becomes an obstacle. |

## Documentation to update in this branch

Per `CLAUDE.md`'s mandatory documentation check (`/doc-check` before committing):

- `docs/architecture.md` §1 — the Symfony 8.1 → 8.4 LTS upgrade path (AC-5.6), and the D-9 runtime
  extension set if it changes the image's shape materially.
- `backend/README.md` — **new**: the containerized command set (install, migrate, test, lint,
  static analysis, OpenAPI export), the `schema:update` prohibition, and the test-database setup.
- Root `README.md` — the backend now serves `/api/health`, `/api/docs` and the OpenAPI JSON; setup
  gains the install + migrate steps.
- `docs/env-vars.md` **and** `backend/.env.example` — only if a new variable proves necessary; both
  together, or `scripts/check-env-vars-drift.sh` fails.
- **No endpoint list in any README** — the generated OpenAPI document is the single source of truth.

---

## Review

**This spec needs your approval before implementation begins.**

Please confirm, in particular, the six decisions — they are choices this spec makes on your behalf,
and each one is expensive to reverse once later prompts build on it:

1. **D-4** — layer directories documented by README, with real code only for the health slice; no
   throwaway `Entity`/`Repository` placeholder.
2. **D-5** — no committed `.env` / `.env.test`; test configuration in `phpunit.xml.dist`, preserving
   `docs/env-vars.md` rule zero.
3. **D-6** — health as an API Platform resource + state provider, never a `#[Route]` controller.
4. **D-7** — backend CI runs on the runner with GitHub Actions `services:` containers for PostgreSQL
   and Redis, pinned to the same versions as `compose.yaml`.
5. **D-8** — PHPStan **level 9** with an empty baseline.
6. **D-9** — Dockerfile gains `pdo_pgsql`, `intl`, `opcache`, `zip` and a Redis client, plus a
   dev-toolchain path that keeps runtime `--no-dev`.

**Verify during implementation, do not assume:** R-1 (that Symfony 8.1 + API Platform 4.3 actually
resolve together on PHP 8.4). If they do not, stop and report rather than substituting older majors.

Next, on approval: branch `feature/backend-skeleton` from `master`, implemented by
`backend-engineer` with its tests in the same step, per the mandatory workflow in `CLAUDE.md`.
