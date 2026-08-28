<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use Psr\Clock\ClockInterface;

/**
 * Instant setlist refresh's backoffice-visibility counters
 * (docs/specs/2026-08-27-instant-setlist-refresh.md, US-9, AC-9.1, AC-9.2). Per-UTC-day Redis keys
 * with a 7-day expiry, consistent with D-68's posture (`SetlistCacheMetrics`'s sibling) — no table,
 * no row per trigger. Read uncached (AC-9.3, D-53).
 */
final class SetlistRefreshMetrics
{
    private const int RETENTION_DAYS = 7;

    /** @var list<'cooldown_active'|'daily_limit_reached'|'budget_reserved'|'budget_exhausted'|'rate_limited'|'upstream_unavailable'> */
    private const array REFUSAL_REASONS = [
        'cooldown_active', 'daily_limit_reached', 'budget_reserved',
        'budget_exhausted', 'rate_limited', 'upstream_unavailable',
    ];

    public function __construct(
        private readonly \Redis $redis,
        private readonly ClockInterface $clock,
    ) {
    }

    public function recordTriggerAccepted(): void
    {
        $this->increment('triggers_accepted');
    }

    /** @param 'cooldown_active'|'daily_limit_reached'|'budget_reserved'|'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason */
    public function recordRefusal(string $reason): void
    {
        $this->increment('refusal:'.$reason);
    }

    public function recordRequestSpent(): void
    {
        $this->increment('requests_spent');
    }

    /** @return array{triggersAccepted: int, requestsSpent: int, refusalsByReason: array<string, int>} */
    public function today(): array
    {
        $refusals = [];
        foreach (self::REFUSAL_REASONS as $reason) {
            $refusals[$reason] = $this->read('refusal:'.$reason);
        }

        return [
            'triggersAccepted' => $this->read('triggers_accepted'),
            'requestsSpent' => $this->read('requests_spent'),
            'refusalsByReason' => $refusals,
        ];
    }

    private function increment(string $suffix): void
    {
        try {
            $key = $this->keyFor($suffix, \DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->redis->incr($key);
            $this->redis->expire($key, self::RETENTION_DAYS * 86400);
        } catch (\Throwable) {
            // Telemetry is best-effort — never let a counter failure break the caller.
        }
    }

    private function read(string $suffix): int
    {
        try {
            $value = $this->redis->get($this->keyFor($suffix, \DateTimeImmutable::createFromInterface($this->clock->now())));

            return \is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function keyFor(string $suffix, \DateTimeImmutable $day): string
    {
        return \sprintf('setlistfm:refreshnow:metrics:%s:%s', $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'), $suffix);
    }
}
