<?php

declare(strict_types=1);

namespace App\Service\Health;

/**
 * The result of one {@see DependencyCheckInterface} — a safe label and a boolean, nothing that
 * could leak internals (AC-2.5).
 */
final readonly class CheckOutcome
{
    public function __construct(
        public string $name,
        public bool $healthy,
    ) {
    }
}
