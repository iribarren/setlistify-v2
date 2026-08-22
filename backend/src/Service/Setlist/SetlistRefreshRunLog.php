<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use Psr\Clock\ClockInterface;

/**
 * The nightly job's last-run outcome (AC-10.7), for the backoffice dashboard (AC-11.3). Redis-only
 * — operational telemetry, same rationale as {@see SetlistCacheMetrics} (D-68).
 */
final class SetlistRefreshRunLog
{
    private const string KEY = 'setlistfm:refresh:last_run';

    public function __construct(
        private readonly \Redis $redis,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @param array{bandsAttempted: int, requestsSpent: int, entriesWritten: int, budgetRemaining: int} $outcome */
    public function recordRun(array $outcome): void
    {
        $payload = [
            ...$outcome,
            'finishedAt' => \DateTimeImmutable::createFromInterface($this->clock->now())->format(\DateTimeInterface::ATOM),
        ];

        try {
            $this->redis->set(self::KEY, json_encode($payload, \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // Telemetry only — a failure to record the outcome must not fail the job itself.
        }
    }

    /**
     * @return array{bandsAttempted: int, requestsSpent: int, entriesWritten: int, budgetRemaining: int, finishedAt: string}|null
     */
    public function lastRun(): ?array
    {
        try {
            $raw = $this->redis->get(self::KEY);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($raw)) {
            return null;
        }

        /** @var array{bandsAttempted: int, requestsSpent: int, entriesWritten: int, budgetRemaining: int, finishedAt: string}|null $decoded */
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
