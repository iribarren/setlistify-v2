<?php

declare(strict_types=1);

namespace App\Service\Health;

/**
 * Runs every registered {@see DependencyCheckInterface} and aggregates the outcome. Contains no
 * knowledge of HTTP, API Platform or the response shape — that belongs to
 * `App\State\HealthStateProvider` (§3, `docs/architecture.md`).
 */
final readonly class HealthChecker
{
    /** @param iterable<DependencyCheckInterface> $checks */
    public function __construct(
        private iterable $checks,
    ) {
    }

    public function check(): HealthReport
    {
        $outcomes = [];

        foreach ($this->checks as $check) {
            $outcomes[] = new CheckOutcome($check->name(), $check->isHealthy());
        }

        return new HealthReport($outcomes);
    }
}
