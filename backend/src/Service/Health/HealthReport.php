<?php

declare(strict_types=1);

namespace App\Service\Health;

/**
 * The aggregate result of every {@see DependencyCheckInterface}, produced by {@see HealthChecker}.
 * Overall health is unhealthy the moment one dependency is — but every dependency's own outcome is
 * always present, healthy or not (AC-2.3: "still returns the status of the healthy ones").
 */
final readonly class HealthReport
{
    /** @param CheckOutcome[] $checks */
    public function __construct(
        public array $checks,
    ) {
    }

    public function isHealthy(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->healthy) {
                return false;
            }
        }

        return true;
    }
}
