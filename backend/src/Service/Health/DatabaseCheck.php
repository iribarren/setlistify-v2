<?php

declare(strict_types=1);

namespace App\Service\Health;

use Doctrine\DBAL\Connection;

/**
 * A real round-trip against PostgreSQL (AC-2.2): connects (or reuses the pooled connection) and
 * runs a trivial `SELECT 1`. The connection's `PDO::ATTR_TIMEOUT` is bounded in
 * `config/packages/doctrine.yaml` (AC-2.4), so a wedged database fails this check quickly instead
 * of hanging the whole health endpoint.
 *
 * Any driver exception is caught here and reduced to a boolean — the exception message (which can
 * contain a host, a port or a fragment of the DSN) never reaches the response (AC-2.5).
 */
final readonly class DatabaseCheck implements DependencyCheckInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function isHealthy(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
