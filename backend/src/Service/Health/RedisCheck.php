<?php

declare(strict_types=1);

namespace App\Service\Health;

/**
 * A real round-trip against Redis (AC-2.2): connects with a short socket timeout and issues a
 * `PING`. `Redis::connect()`'s timeout and `Redis::OPT_READ_TIMEOUT` bound the whole check to
 * {@see self::TIMEOUT_SECONDS} (AC-2.4), so a wedged Redis cannot make the health endpoint hang.
 *
 * A fresh connection is opened per check rather than reusing a shared client: the health check
 * must observe the real current state of the dependency, not a client that connected successfully
 * minutes ago and has been silently failing since (AC-2.2).
 *
 * Any exception is reduced to a boolean here — no host, port or Redis error string ever reaches
 * the response (AC-2.5).
 */
final readonly class RedisCheck implements DependencyCheckInterface
{
    private const float TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private string $redisUrl,
    ) {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function isHealthy(): bool
    {
        $parts = parse_url($this->redisUrl);

        if (false === $parts || !isset($parts['host'])) {
            return false;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 6379;

        $redis = new \Redis();

        try {
            if (!$redis->connect($host, $port, self::TIMEOUT_SECONDS)) {
                return false;
            }

            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::TIMEOUT_SECONDS);

            return true === $redis->ping();
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $redis->close();
            } catch (\Throwable) {
                // Already disconnected — nothing to clean up.
            }
        }
    }
}
