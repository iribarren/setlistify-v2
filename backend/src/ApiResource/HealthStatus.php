<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\HealthStateProvider;

/**
 * The public shape of `GET /api/health` — an API Platform resource + state provider (D-6,
 * `docs/specs/2026-08-21-backend-skeleton.md`), never a bare `#[Route]` controller, so it is
 * generated into the OpenAPI document rather than able to drift from it (AC-3.4).
 *
 * No business logic lives here: {@see HealthStateProvider} delegates the actual dependency
 * round-trips to `App\Service\Health\HealthChecker`. The 200 response schema is generated
 * automatically from this class's properties; the additional 503 response (AC-3.3) is appended by
 * `App\OpenApi\HealthOpenApiFactory`, since a resource's `#[Get]` attribute can only describe a
 * single static response and would otherwise silently drop the auto-generated `Health` schema.
 */
#[ApiResource(
    shortName: 'Health',
    description: 'Reports whether the application and its dependencies (database, Redis) are actually usable — not just that the container is up.',
    operations: [
        new Get(
            uriTemplate: '/health',
            provider: HealthStateProvider::class,
            output: self::class,
        ),
    ],
)]
final readonly class HealthStatus
{
    public function __construct(
        /** Overall status: `ok` when every dependency is healthy, `error` otherwise. */
        public string $status,
        /** `ok` or `error` — a real round-trip result, never a configuration read. */
        public string $database,
        /** `ok` or `error` — a real round-trip result, never a configuration read. */
        public string $redis,
    ) {
    }
}
