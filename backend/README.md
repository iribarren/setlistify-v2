# Setlistify backend

Symfony 8.1 on PHP 8.4, API Platform 4.3, Doctrine ORM against the compose PostgreSQL. Runs in the
`backend` container from the root `compose.yaml` — no host-side PHP, no second runtime.

See the root [`README.md`](../README.md) for bringing the whole stack up, and
`docs/architecture.md` for the system design. This file covers backend-specific commands only.

**Every command below runs inside the container** (`docker compose exec backend ...`), per the
Execution Policy in the root `CLAUDE.md`. None of it needs PHP, Composer or an extension installed
on the host.

## First run

```bash
cp backend/.env.example backend/.env.local   # once per clone, gitignored
export APP_UID=$(id -u) APP_GID=$(id -g)     # so container-written files are owned by you, not root
docker compose up -d --build backend

# Install dependencies (with dev tools — PHPUnit, PHPStan, CS-Fixer) into the bind mount.
# The image itself only ships production dependencies (--no-dev); this is what puts the dev
# toolchain into ./backend/vendor for local use.
docker compose exec backend composer install

# Apply the baseline migration.
docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction
```

`docker compose ps` should report `backend` as `healthy` — its healthcheck is a real
`GET /api/health` round-trip against PostgreSQL and Redis, not just "the process is running".

## Everyday commands

| Task | Command |
|---|---|
| Install/update dependencies | `docker compose exec backend composer install` |
| Run a console command | `docker compose exec backend bin/console <command>` |
| Apply migrations | `docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction` |
| Generate a new migration from entity changes | `docker compose exec backend bin/console doctrine:migrations:diff` |
| Run the test suite | `docker compose exec backend composer test` |
| Set up / refresh the test database | `docker compose exec backend composer test:db` |
| Static analysis (PHPStan, level 9) | `docker compose exec backend composer phpstan` |
| Code style check (dry run) | `docker compose exec backend composer cs-fixer` |
| Code style, apply fixes | `docker compose exec backend composer cs-fixer:fix` |
| Lint (CS-Fixer + PHPStan — what CI runs) | `docker compose exec backend composer lint` |
| Export the OpenAPI document | `docker compose exec backend bin/console api:openapi:export --output=openapi.json` |

The OpenAPI document is also fetchable live at `GET /api/docs.jsonopenapi`, and its rendered UI at
`GET /api/docs`. **The generated OpenAPI document is the single source of truth for endpoints** —
this README does not list them (`CLAUDE.md`, API Contract).

## Migrations: the only way schema changes

**`doctrine:schema:update` is never used** — not in a documented command, not in a script, not in
CI, not in a test bootstrap. Every schema change is a versioned file in `migrations/`, committed and
reviewed like any other code change (AC-4.4, `docs/specs/2026-08-21-backend-skeleton.md`). If you
find yourself reaching for `schema:update` "just this once," generate a migration instead:

```bash
docker compose exec backend bin/console doctrine:migrations:diff
docker compose exec backend bin/console doctrine:migrations:migrate --no-interaction
```

## Testing

- `docker compose exec backend composer test:db` creates `<database>_test` (a separate database on
  the same PostgreSQL instance — never your dev database) and migrates it. Run it once, and again
  whenever a new migration lands.
- `docker compose exec backend composer test` runs PHPUnit.
- No `.env.test` is committed — the `test` environment's overrides (`APP_ENV=test`, `KERNEL_CLASS`)
  live in `phpunit.xml.dist`; infrastructure values (`DATABASE_URL`, `REDIS_URL`, ...) come from the
  same process environment as `dev` (D-5). Doctrine's `when@test: dbal: dbname_suffix: '_test'`
  (`config/packages/doctrine.yaml`) is what points the run at the separate database.
- The suite never calls setlist.fm, Spotify or YouTube (`docs/architecture.md`, decision D-2).

## Static analysis and style

- PHPStan runs at **level 9** with an **empty baseline** (`phpstan.neon.dist`) — a baseline created
  now would silently become permanent. If level 9 ever proves genuinely unworkable, that has to be
  recorded as a decision in `docs/specs/2026-08-21-backend-skeleton.md`, not quietly patched around.
- PHP-CS-Fixer uses the `@Symfony` rule set (`.php-cs-fixer.dist.php`).
- `composer lint` runs both — the same command CI runs.

## Layout (`src/`)

Every layer from `docs/architecture.md` §3 exists as a directory with its own `README.md` stating
its rule. Most are empty on purpose: this feature ships no domain (D-4,
`docs/specs/2026-08-21-backend-skeleton.md`) — `Entity/`, `Repository/`, `Controller/Admin/` and the
provider-specific `Service/` directories are filled by later prompts. The one real, working example
is the health slice: `ApiResource/HealthStatus.php` → `State/HealthStateProvider.php` →
`Service/Health/`.

## Environment variables

No `.env` or `.env.test` is committed (rule zero, `docs/env-vars.md`). Local values live in
`backend/.env.local` (gitignored, copied from `.env.example`); see the root `README.md` and
`docs/env-vars.md` for the full reference and how CI/production supply values instead.
