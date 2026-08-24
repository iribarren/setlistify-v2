<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The single gate every outbound setlist.fm request passes through (D-61, AC-7.3): a Redis-backed
 * per-second token bucket, a Redis-backed daily counter keyed by UTC calendar date, and a
 * Redis-shared circuit breaker (D-64). All three are consulted by {@see acquire()} — no code path
 * may reach {@see SetlistFmClient} without a token from here (AC-7.1..AC-7.7).
 *
 * **Fail-closed** (AC-7.6, D-61): any Redis failure refuses the request with `upstream_unavailable`
 * rather than allowing it through unlimited — the same posture as
 * {@see \App\Service\Security\RateLimiterGuard}.
 *
 * The daily counter is only incremented once a request has actually been cleared to go out
 * (AC-7.7): a caller refused by the per-second wait or the breaker never touches the daily budget,
 * because a blocked attempt is not "a request issued".
 */
final class SetlistFmBudget
{
    private const int BREAKER_FAILURE_THRESHOLD = 5;
    private const int BREAKER_COOLDOWN_SECONDS = 60;
    private const float TOKEN_POLL_INTERVAL_SECONDS = 0.05;

    /**
     * @param (\Closure(): float)|null   $currentTimestamp seam for deterministic tests of the rate-token
     *                                                      loop; defaults to the real wall clock (`microtime(true)`)
     * @param (\Closure(float): void)|null $sleep           seam for deterministic tests of the rate-token
     *                                                      loop; defaults to a real `usleep()`
     */
    public function __construct(
        private readonly \Redis $redis,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $setlistfmLogger,
        private readonly int $ratePerSecond,
        private readonly int $dailyBudget,
        private readonly float $tokenWaitSeconds,
        private readonly ?\Closure $currentTimestamp = null,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /**
     * Reserves one request slot. `$waitOverrideSeconds` lets the nightly job (D-62 — "the one
     * caller allowed to be patient") wait longer than a web request's bounded default.
     */
    public function acquire(?float $waitOverrideSeconds = null): SetlistFmBudgetDecision
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        try {
            if ($this->isBreakerOpen($now)) {
                return SetlistFmBudgetDecision::refuse('upstream_unavailable');
            }

            $dailyKey = $this->dailyKey($now);
            $resetAt = $this->nextUtcMidnight($now);

            $usedRaw = $this->redis->get($dailyKey);
            $used = \is_numeric($usedRaw) ? (int) $usedRaw : 0;
            if ($used >= $this->dailyBudget) {
                $this->logBudgetExhaustedOnce($now);

                return SetlistFmBudgetDecision::refuse('budget_exhausted', $resetAt);
            }

            $wait = $waitOverrideSeconds ?? $this->tokenWaitSeconds;
            if (!$this->tryAcquireRateToken($wait)) {
                return SetlistFmBudgetDecision::refuse('rate_limited');
            }

            // Only now — cleared by both the breaker and the rate token — does this count as a
            // request actually being issued (AC-7.7).
            $newUsed = (int) $this->redis->incr($dailyKey);
            if (1 === $newUsed) {
                $ttl = max(1, $resetAt->getTimestamp() - $now->getTimestamp());
                $this->redis->expire($dailyKey, $ttl);
            }

            return SetlistFmBudgetDecision::allow();
        } catch (\Throwable $e) {
            $this->setlistfmLogger->error('setlist.fm budget gate: Redis unavailable — failing closed, no outbound call made', [
                'exception' => $e::class,
            ]);

            return SetlistFmBudgetDecision::refuse('upstream_unavailable');
        }
    }

    /** AC-9.4/D-64: a transient failure (429, 5xx, connection/timeout) counts toward the breaker. */
    public function recordTransientFailure(): void
    {
        try {
            $failures = (int) $this->redis->incr('setlistfm:breaker:failures');
            $this->redis->expire('setlistfm:breaker:failures', self::BREAKER_COOLDOWN_SECONDS * 4);

            if ($failures >= self::BREAKER_FAILURE_THRESHOLD) {
                $now = $this->clock->now();
                $openUntil = $now->getTimestamp() + self::BREAKER_COOLDOWN_SECONDS;
                $this->redis->set('setlistfm:breaker:open_until', (string) $openUntil);
                $this->setlistfmLogger->warning('setlist.fm circuit breaker opened after consecutive transient failures', [
                    'failures' => $failures,
                    'cooldown_seconds' => self::BREAKER_COOLDOWN_SECONDS,
                ]);
            }
        } catch (\Throwable) {
            // Fail-closed already covers the "can't reach Redis" case at acquire() time; a failure
            // to record a breaker signal must not itself throw into the caller's error path.
        }
    }

    public function recordSuccess(): void
    {
        try {
            $this->redis->del('setlistfm:breaker:failures');
        } catch (\Throwable) {
        }
    }

    /** @return 'closed'|'open' */
    public function breakerState(): string
    {
        try {
            return $this->isBreakerOpen(\DateTimeImmutable::createFromInterface($this->clock->now())) ? 'open' : 'closed';
        } catch (\Throwable) {
            return 'closed';
        }
    }

    /**
     * Uncached (D-53, AC-11.7) — read fresh from Redis every call, for the backoffice dashboard.
     *
     * @return array{used: int, budget: int, resetAt: \DateTimeImmutable}
     */
    public function dailyUsage(): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $used = 0;

        try {
            $usedRaw = $this->redis->get($this->dailyKey($now));
            $used = \is_numeric($usedRaw) ? (int) $usedRaw : 0;
        } catch (\Throwable) {
            // Dashboard degrades to "0 used" rather than throwing (US-11 is a visibility feature,
            // not a hard dependency) — the panel's own breaker-state read already surfaces "Redis
            // is unreachable" via breakerState() defaulting closed only when truly unknown.
        }

        return ['used' => $used, 'budget' => $this->dailyBudget, 'resetAt' => $this->nextUtcMidnight($now)];
    }

    private function isBreakerOpen(\DateTimeImmutable $now): bool
    {
        $openUntil = $this->redis->get('setlistfm:breaker:open_until');
        if (!\is_numeric($openUntil)) {
            return false;
        }

        return (int) $openUntil > $now->getTimestamp();
    }

    private function tryAcquireRateToken(float $waitSeconds): bool
    {
        $deadline = $this->currentTimestamp() + $waitSeconds;

        while (true) {
            $second = (int) floor($this->currentTimestamp());
            $key = \sprintf('setlistfm:rate:%d', $second);
            $count = (int) $this->redis->incr($key);
            if (1 === $count) {
                $this->redis->expire($key, 2);
            }

            if ($count <= $this->ratePerSecond) {
                return true;
            }

            if ($this->currentTimestamp() >= $deadline) {
                return false;
            }

            $this->sleep(self::TOKEN_POLL_INTERVAL_SECONDS);
        }
    }

    private function currentTimestamp(): float
    {
        return null !== $this->currentTimestamp ? ($this->currentTimestamp)() : microtime(true);
    }

    private function sleep(float $seconds): void
    {
        if (null !== $this->sleep) {
            ($this->sleep)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    private function dailyKey(\DateTimeImmutable $now): string
    {
        return 'setlistfm:budget:'.$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    private function nextUtcMidnight(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return $utc->modify('tomorrow midnight');
    }

    /** AC-8.7: warn once per exhaustion transition, not once per request. */
    private function logBudgetExhaustedOnce(\DateTimeImmutable $now): void
    {
        $flagKey = $this->dailyKey($now).':exhausted_logged';
        $setSucceeded = (bool) $this->redis->set($flagKey, '1', ['nx', 'ex' => 86400]);

        if ($setSucceeded) {
            $this->setlistfmLogger->warning('setlist.fm daily budget exhausted', [
                'budget' => $this->dailyBudget,
            ]);
        }
    }
}
