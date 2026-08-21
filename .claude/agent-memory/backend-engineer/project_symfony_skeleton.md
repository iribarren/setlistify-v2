---
name: project_symfony_skeleton
description: How the Setlistify backend (Symfony 8.1/API Platform 4.3/PHP 8.4) is wired — env handling, health check pattern, RFC7807, CI. Read before touching backend/.
metadata:
  type: project
---

Implemented in `feature/backend-skeleton` (docs/specs/2026-08-21-backend-skeleton.md), merged to
master as commit 0c79888 (2026-08-21).

**No `.env`/`.env.test` is ever committed (D-5).** `composer.json`'s `extra.runtime.disable_dotenv:
true` makes symfony/runtime skip Dotenv entirely — the app reads only the real process environment
(`backend/.env.local` in dev, container/CI env vars elsewhere). `phpunit.xml.dist` sets `APP_ENV=test`
and `KERNEL_CLASS` via **both** `<env>` and `<server>` — `<server>`-only silently loses to the
container's real `$_ENV['APP_ENV']` because `KernelTestCase::createKernel()` checks `$_ENV` before
`$_SERVER`. Any new required env var needs a value in `phpunit.xml.dist` too if tests need it.

**Health endpoint (`GET /api/health`) is the reference pattern for API Platform resources** — never
a `#[Route]` controller (D-6). Shape: `ApiResource/<X>.php` (DTO+attribute) → `State/<X>StateProvider`
(no business logic) → `Service/<X>/` (real logic). To add extra OpenAPI response codes beyond the
resource's default (e.g. a 503 for health), do NOT hand-roll the `openapi:` param on the operation
attribute — it fully replaces API Platform's auto-generated schema/response and orphans the
`components.schemas` entry. Instead decorate `api_platform.openapi.factory` (see
`src/OpenApi/HealthOpenApiFactory.php`) and reuse the existing response's `$ref`.

**RFC7807 for unmatched routes requires `api_platform.error_formats` + `handle_symfony_errors:
true`** in `config/packages/api_platform.yaml`. Without `handle_symfony_errors: true`, only requests
that matched an API Platform resource get problem+json; a typo'd path (`ApiPlatform\Symfony\
EventListener\ExceptionListener`) falls through to Symfony's HTML error page. Leak suppression
(AC-6.3) is driven purely by the kernel's `debug` flag, not by `APP_ENV` name — `WebTestCase::
createClient(['debug' => false])` is how tests simulate prod's no-leak behavior without an actual
prod boot.

**Swagger UI (`/api/docs`) needs `symfony/twig-bundle`** — `enable_swagger_ui` defaults to
`class_exists(TwigBundle::class)`, so without it the docs UI silently 404s ("Swagger UI, ReDoc and
Scalar are disabled").

**`X-Powered-By` and the Caddy `Server` header are suppressed at the Dockerfile level**, not in app
code: `expose_php = Off` in a php.ini conf.d file, and `CADDY_SERVER_EXTRA_DIRECTIVES="header
-Server"` env var (FrankenPHP's base Caddyfile reads that var natively — no custom Caddyfile
needed).

**Security headers** live in `src/EventSubscriber/SecurityHeadersSubscriber.php`, path-scoped: a
strict `default-src 'none'` CSP for the API, a relaxed same-origin CSP (with `unsafe-eval` —
swagger-ui-bundle.js uses `new Function()`) for `/api/docs*`.

**PHPStan level 9, empty baseline held cleanly** — no reduction needed. `phpstan-symfony` needs a
warmed dev container (`bin/console cache:warmup --env=dev`) for `containerXmlPath` to exist;
CI runs this before `composer lint`. `phpstan-doctrine` extension is included but
`objectManagerLoader` is intentionally omitted (no entities exist yet — see D-4).

**CI backend job uses GitHub Actions `services:` containers** (postgres:17.6-alpine, redis:7.4-alpine,
same digests as `compose.yaml`), NOT `docker compose exec` — this was a deliberate late change to the
spec's D-7 (the draft originally proposed compose-exec; the user asked for `services:` explicitly).
Extensions in `setup-php` are hand-kept in sync with `docker/backend/Dockerfile`'s base stage
(`pdo_pgsql, intl, opcache, zip, redis`) — flagged as R-6, drift risk to watch.

See also [[project_layering_conventions]] (not yet written) for the `src/` directory rules.
