<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use App\Entity\Band;
use App\Entity\User;
use Symfony\Component\Lock\LockFactory;

/**
 * The three throttles (D-259), the per-band single-flight record and the Redis refresh-state record
 * (D-264, AC-3.4, AC-6.4) — docs/specs/2026-08-27-instant-setlist-refresh.md.
 *
 * All Redis reads/writes fail closed (AC-5.3, D-61's posture): any `\Throwable` refuses with
 * `upstream_unavailable` rather than letting the trigger through unlimited.
 *
 * A `symfony/lock` keyed per band serializes the whole check-then-write sequence (AC-5.6, D-262) —
 * short-held (Redis ops only, never the actual refresh work), so a blocking acquire is safe.
 */
final class SetlistRefreshCoordinator
{
    private const string LOCK_PREFIX = 'setlistfm:refresh:coord:';
    private const float LOCK_TTL_SECONDS = 10.0;
    private const string STATE_KEY_PREFIX = 'setlistfm:refresh:state:';
    private const int STATE_TTL_SECONDS = 86400;
    private const string COOLDOWN_KEY_PREFIX = 'setlistfm:refresh:cooldown:';
    private const string DAILY_CAP_KEY_PREFIX = 'setlistfm:refresh:dailycap:';

    public function __construct(
        private readonly \Redis $redis,
        private readonly SetlistFmBudget $budget,
        private readonly SetlistRefreshMetrics $metrics,
        private readonly LockFactory $lockFactory,
        private readonly int $cooldownSeconds,
        private readonly int $dailyPerUserCap,
        private readonly float $budgetReserveShare,
    ) {
    }

    /** AC-1.5/AC-5.1: the trigger path — checks in-flight, then all three throttles, in order. */
    public function trigger(Band $band, User $user, \DateTimeImmutable $now): SetlistRefreshDecision
    {
        return $this->withBandLock((int) $band->getId(), function () use ($band, $user, $now): SetlistRefreshDecision {
            try {
                $existing = $this->readRecord((int) $band->getId());
                if (null !== $existing && $existing->isActive()) {
                    return SetlistRefreshDecision::alreadyInFlight($existing);
                }

                $cooldownRefusal = $this->checkCooldown((int) $band->getId(), $now);
                if (null !== $cooldownRefusal) {
                    $this->metrics->recordRefusal('cooldown_active');

                    return $cooldownRefusal;
                }

                $capRefusal = $this->checkDailyCap((int) $user->getId(), $now);
                if (null !== $capRefusal) {
                    $this->metrics->recordRefusal('daily_limit_reached');

                    return $capRefusal;
                }

                $reserveRefusal = $this->checkBudgetReserve($now);
                if (null !== $reserveRefusal) {
                    $this->metrics->recordRefusal('budget_reserved');

                    return $reserveRefusal;
                }

                $this->consumeDailyCap((int) $user->getId(), $now);
                $this->setCooldown((int) $band->getId(), $now);
                $this->metrics->recordTriggerAccepted();

                $record = new SetlistRefreshRecord(
                    bandId: (int) $band->getId(),
                    state: 'queued',
                    requestedAt: $now,
                    finishedAt: null,
                    bandStateBefore: $band->getSetlistfmResolutionState(),
                    bandStateAfter: null,
                    candidates: [],
                    cooldownUntil: $now->modify(\sprintf('+%d seconds', $this->cooldownSeconds)),
                    failureReason: null,
                );
                $this->writeRecord($record);

                return SetlistRefreshDecision::accepted($record);
            } catch (\Throwable) {
                $this->metrics->recordRefusal('upstream_unavailable');

                return SetlistRefreshDecision::refused('upstream_unavailable', $now->modify('+1 minute'));
            }
        });
    }

    /**
     * AC-6.12/D-277: the pick's completion — daily cap and budget reserve still apply (it spends a
     * real request), but exempt from the cooldown (the band's identity has just changed, so this is
     * provably not the deterministic repeat the cooldown exists to refuse, D-277). Must be called
     * from inside {@see self::withBandLock()} by the caller (the pick already holds the lock for its
     * own write).
     */
    public function acceptPickCompletion(Band $band, User $user, \DateTimeImmutable $now): SetlistRefreshDecision
    {
        try {
            $capRefusal = $this->checkDailyCap((int) $user->getId(), $now);
            if (null !== $capRefusal) {
                $this->metrics->recordRefusal('daily_limit_reached');

                return $capRefusal;
            }

            $reserveRefusal = $this->checkBudgetReserve($now);
            if (null !== $reserveRefusal) {
                $this->metrics->recordRefusal('budget_reserved');

                return $reserveRefusal;
            }

            $this->consumeDailyCap((int) $user->getId(), $now);

            $record = new SetlistRefreshRecord(
                bandId: (int) $band->getId(),
                state: 'queued',
                requestedAt: $now,
                finishedAt: null,
                bandStateBefore: $band->getSetlistfmResolutionState(),
                bandStateAfter: null,
                candidates: [],
                cooldownUntil: null,
                failureReason: null,
            );
            $this->writeRecord($record);

            return SetlistRefreshDecision::accepted($record);
        } catch (\Throwable) {
            return SetlistRefreshDecision::refused('upstream_unavailable', $now->modify('+1 minute'));
        }
    }

    public function markRunning(int $bandId): void
    {
        $record = $this->readRecord($bandId);
        if (null === $record) {
            return;
        }

        $this->writeRecord(new SetlistRefreshRecord(
            bandId: $record->bandId,
            state: 'running',
            requestedAt: $record->requestedAt,
            finishedAt: null,
            bandStateBefore: $record->bandStateBefore,
            bandStateAfter: null,
            candidates: [],
            cooldownUntil: $record->cooldownUntil,
            failureReason: null,
        ));
    }

    /** @param list<ArtistSearchCandidate> $candidates */
    public function markSucceeded(int $bandId, string $bandStateAfter, array $candidates, CachedFetch $freshness, \DateTimeImmutable $now): void
    {
        $record = $this->readRecord($bandId);
        $this->writeRecord(new SetlistRefreshRecord(
            bandId: $bandId,
            state: 'succeeded',
            requestedAt: null !== $record ? $record->requestedAt : $now,
            finishedAt: $now,
            bandStateBefore: null !== $record ? $record->bandStateBefore : $bandStateAfter,
            bandStateAfter: $bandStateAfter,
            candidates: $candidates,
            cooldownUntil: $record?->cooldownUntil,
            failureReason: null,
            freshnessSource: $freshness->source,
            freshnessFetchedAt: $freshness->fetchedAt,
            freshnessStale: $freshness->stale,
            freshnessReason: $freshness->reason,
            freshnessBudgetResetAt: $freshness->budgetResetAt,
        ));
    }

    /** @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable' $reason */
    public function markFailed(int $bandId, string $reason, ?\DateTimeImmutable $budgetResetAt, \DateTimeImmutable $now): void
    {
        $record = $this->readRecord($bandId);
        $this->writeRecord(new SetlistRefreshRecord(
            bandId: $bandId,
            state: 'failed',
            requestedAt: null !== $record ? $record->requestedAt : $now,
            finishedAt: $now,
            bandStateBefore: null !== $record ? $record->bandStateBefore : 'unresolved',
            bandStateAfter: $record?->bandStateAfter,
            candidates: null !== $record ? $record->candidates : [],
            cooldownUntil: $record?->cooldownUntil,
            failureReason: $reason,
            freshnessSource: 'cache',
            freshnessFetchedAt: null,
            freshnessStale: true,
            freshnessReason: $reason,
            freshnessBudgetResetAt: $budgetResetAt,
        ));
    }

    public function getRecord(int $bandId): ?SetlistRefreshRecord
    {
        try {
            return $this->readRecord($bandId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Serializes the whole check-then-write sequence per band (AC-5.6, AC-6.14) — used by
     * {@see self::trigger()} internally, and by `ResolveBandIdentityProcessor` to wrap its own
     * read-check-write-then-complete sequence with the same lock.
     *
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    public function withBandLock(int $bandId, \Closure $fn): mixed
    {
        $lock = $this->lockFactory->createLock(self::LOCK_PREFIX.$bandId, self::LOCK_TTL_SECONDS);
        $lock->acquire(true);

        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    private function checkCooldown(int $bandId, \DateTimeImmutable $now): ?SetlistRefreshDecision
    {
        $ttl = $this->redis->ttl($this->cooldownKey($bandId));
        if (\is_int($ttl) && $ttl > 0) {
            return SetlistRefreshDecision::refused('cooldown_active', $now->modify(\sprintf('+%d seconds', $ttl)));
        }

        return null;
    }

    private function setCooldown(int $bandId, \DateTimeImmutable $now): void
    {
        $this->redis->setex($this->cooldownKey($bandId), max(1, $this->cooldownSeconds), '1');
    }

    private function checkDailyCap(int $userId, \DateTimeImmutable $now): ?SetlistRefreshDecision
    {
        $used = $this->dailyCapUsed($userId, $now);
        if ($used >= $this->dailyPerUserCap) {
            return SetlistRefreshDecision::refused('daily_limit_reached', $this->nextUtcMidnight($now));
        }

        return null;
    }

    private function consumeDailyCap(int $userId, \DateTimeImmutable $now): void
    {
        $key = $this->dailyCapKey($userId, $now);
        $newCount = (int) $this->redis->incr($key);
        if (1 === $newCount) {
            $ttl = max(1, $this->nextUtcMidnight($now)->getTimestamp() - $now->getTimestamp());
            $this->redis->expire($key, $ttl);
        }
    }

    private function dailyCapUsed(int $userId, \DateTimeImmutable $now): int
    {
        $raw = $this->redis->get($this->dailyCapKey($userId, $now));

        return \is_numeric($raw) ? (int) $raw : 0;
    }

    private function checkBudgetReserve(\DateTimeImmutable $now): ?SetlistRefreshDecision
    {
        $usage = $this->budget->dailyUsage();
        $remaining = max(0, $usage['budget'] - $usage['used']);
        $reserveFloor = $this->budgetReserveShare * $usage['budget'];

        if ($remaining < $reserveFloor) {
            return SetlistRefreshDecision::refused('budget_reserved', $usage['resetAt']);
        }

        return null;
    }

    private function readRecord(int $bandId): ?SetlistRefreshRecord
    {
        $raw = $this->redis->get($this->stateKey($bandId));
        if (!\is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return SetlistRefreshRecord::fromArray($decoded);
    }

    private function writeRecord(SetlistRefreshRecord $record): void
    {
        $this->redis->setex($this->stateKey($record->bandId), self::STATE_TTL_SECONDS, json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    private function stateKey(int $bandId): string
    {
        return self::STATE_KEY_PREFIX.$bandId;
    }

    private function cooldownKey(int $bandId): string
    {
        return self::COOLDOWN_KEY_PREFIX.$bandId;
    }

    private function dailyCapKey(int $userId, \DateTimeImmutable $now): string
    {
        return \sprintf('%s%d:%s', self::DAILY_CAP_KEY_PREFIX, $userId, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'));
    }

    private function nextUtcMidnight(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->modify('tomorrow midnight');
    }
}
