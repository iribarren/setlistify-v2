<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\HealthStatus;
use App\Service\Health\HealthChecker;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Platform state provider for `GET /api/health` (D-6). No business logic: it delegates the
 * actual dependency round-trips to {@see HealthChecker} and shapes the result into
 * {@see HealthStatus}.
 *
 * The overall status also drives the HTTP status code (200 healthy, 503 unhealthy — AC-2.3): API
 * Platform reads the status from the resolved `Operation`, so an unhealthy report replaces the
 * `_api_operation` request attribute with a 503-status copy of the same operation. This is the
 * same mechanism API Platform's own SwaggerUiProvider uses to vary a response's status code from
 * within a provider.
 *
 * @implements ProviderInterface<HealthStatus>
 */
final readonly class HealthStateProvider implements ProviderInterface
{
    public function __construct(
        private HealthChecker $healthChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HealthStatus
    {
        $report = $this->healthChecker->check();

        $byName = [];
        foreach ($report->checks as $check) {
            $byName[$check->name] = $check->healthy ? 'ok' : 'error';
        }

        $status = new HealthStatus(
            status: $report->isHealthy() ? 'ok' : 'error',
            database: $byName['database'] ?? 'error',
            redis: $byName['redis'] ?? 'error',
        );

        if (!$report->isHealthy() && $operation instanceof HttpOperation) {
            /** @var \Symfony\Component\HttpFoundation\Request|null $request */
            $request = $context['request'] ?? null;
            $request?->attributes->set('_api_operation', $operation->withStatus(Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $status;
    }
}
