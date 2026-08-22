<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use Psr\Clock\ClockInterface;

/**
 * Cache hit/miss counters, per UTC calendar day, per tier (D-68). Deliberately Redis-only,
 * short-lived (7 days) operational telemetry — not domain data, not worth a table row per read.
 * Consumed by the backoffice dashboard panel (AC-11.2, AC-11.7 — read uncached, every time).
 */
final class SetlistCacheMetrics
{
    private const int RETENTION_DAYS = 7;

    /** @var list<'redis'|'postgres'|'outbound'> */
    private const array TIERS = ['redis', 'postgres', 'outbound'];

    public function __construct(
        private readonly \Redis $redis,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param 'redis'|'postgres'|'outbound' $tier */
    public function recordHit(string $tier): void
    {
        try {
            $key = $this->keyFor($tier, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->redis->incr($key);
            $this->redis->expire($key, self::RETENTION_DAYS * 86400);
        } catch (\Throwable) {
            // Metrics are best-effort; never let a counter failure break a real read.
        }
    }

    /** @return array{redis: int, postgres: int, outbound: int, total: int, hitRate: float} */
    public function today(): array
    {
        return $this->aggregate([\DateTimeImmutable::createFromInterface($this->clock->now())]);
    }

    /** @return array{redis: int, postgres: int, outbound: int, total: int, hitRate: float} */
    public function trailing7Days(): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $days = [];
        for ($i = 0; $i < self::RETENTION_DAYS; ++$i) {
            $days[] = $now->modify(\sprintf('-%d days', $i));
        }

        return $this->aggregate($days);
    }

    /**
     * @param list<\DateTimeImmutable> $days
     *
     * @return array{redis: int, postgres: int, outbound: int, total: int, hitRate: float}
     */
    private function aggregate(array $days): array
    {
        $totals = ['redis' => 0, 'postgres' => 0, 'outbound' => 0];

        try {
            foreach ($days as $day) {
                foreach (self::TIERS as $tier) {
                    $value = $this->redis->get($this->keyFor($tier, $day));
                    $totals[$tier] += \is_numeric($value) ? (int) $value : 0;
                }
            }
        } catch (\Throwable) {
            // Best-effort — a Redis outage shows a zeroed panel rather than a broken dashboard.
        }

        $total = array_sum($totals);
        $hits = $totals['redis'] + $totals['postgres'];
        $hitRate = $total > 0 ? $hits / $total : 0.0;

        return [...$totals, 'total' => $total, 'hitRate' => round($hitRate, 4)];
    }

    private function keyFor(string $tier, \DateTimeImmutable $day): string
    {
        return \sprintf('setlistfm:metrics:%s:%s', $day->format('Y-m-d'), $tier);
    }
}
