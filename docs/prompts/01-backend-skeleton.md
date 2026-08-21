# 01 — Backend skeleton

**Command:** `/feature backend-skeleton` · **Agent:** `backend-engineer` · **Depends on:** 00

## Goal
A running Symfony application that exposes a health endpoint and a generated OpenAPI document, with
the layering, testing and quality tooling in place that every later backend prompt will build on.

## Context
`docker compose up` works but the backend container serves nothing. Read `docs/architecture.md` §3
for the layer rules — this prompt creates those directories and establishes the conventions, so
getting it right here saves re-litigating it in every later prompt.

Stack floor: PHP 8.4 (Symfony 8.1 requires it), Symfony 8.1, API Platform 4.3, Doctrine ORM.

## Scope
- Symfony 8.1 skeleton in `backend/`, running in the container from prompt 00.
- API Platform 4.3 installed and serving `/api/docs` plus the OpenAPI JSON.
- Doctrine configured against the compose PostgreSQL, with migrations set up (no `schema:update`).
- The directory layering from `docs/architecture.md` §3, created with a representative example in
  each so the conventions are visible rather than described.
- `GET /api/health` — liveness plus database and Redis connectivity, as an API Platform resource so
  it lands in the generated spec.
- RFC 7807 problem-details error format, configured globally.
- PHPUnit with a `test` environment and a separate test database; a passing test for the health
  endpoint.
- Static analysis: PHPStan (level 8 or higher) and PHP-CS-Fixer, both wired into CI from prompt 00.
- CORS configured from `CORS_ALLOW_ORIGIN`, never `*`.
- Security headers on all responses.

## Out of scope
- Authentication — prompt 04.
- Any domain entity — prompt 05 onward.
- The backoffice — prompt 08.

## Acceptance criteria
- [ ] `GET /api/health` returns 200 with database and Redis status; it reports 503 when a dependency
      is genuinely down.
- [ ] `/api/docs` renders and the OpenAPI JSON is fetchable — prompt 03 generates the client from it.
- [ ] `bin/console doctrine:migrations:migrate` runs clean against an empty database.
- [ ] `vendor/bin/phpunit` is green; `vendor/bin/phpstan` passes at the configured level.
- [ ] An error response is RFC 7807 shaped, and stack traces never leak in `prod`.
- [ ] CI runs migrations, tests, PHPStan and CS-Fixer, and is green.
- [ ] Every command runs inside the container (`docker compose exec backend …`).

## Risks & open questions
- API Platform's conventions can be fought or embraced. Embrace them: hand-rolled controllers that
  bypass resources will drift out of the OpenAPI spec, which breaks the generated client contract
  that `CLAUDE.md` depends on.
- Set the PHPStan level high now. Raising it later across an existing codebase never happens.
- Symfony 8.1 is not an LTS; 8.4 will be. Note the upgrade path in `docs/architecture.md` rather than
  discovering it under time pressure.
