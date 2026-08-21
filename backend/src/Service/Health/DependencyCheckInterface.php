<?php

declare(strict_types=1);

namespace App\Service\Health;

/**
 * One dependency's real round-trip check (AC-2.2) — a trivial query, a PING-equivalent, never a
 * configuration read. Implementations must never let an exception escape with anything that could
 * leak a credential, DSN, host, port or driver message (AC-2.5); {@see HealthChecker} only reads
 * the boolean outcome.
 */
interface DependencyCheckInterface
{
    /** A short, safe label — never a value that could contain connection details. */
    public function name(): string;

    /** True if the round-trip succeeded within the check's own timeout (AC-2.4). */
    public function isHealthy(): bool;
}
