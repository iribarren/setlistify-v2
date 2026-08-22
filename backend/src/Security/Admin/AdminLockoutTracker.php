<?php

declare(strict_types=1);

namespace App\Security\Admin;

use Psr\Cache\CacheItemPoolInterface;

/**
 * AC-4.2: after 10 consecutive failed admin logins against one account, that account is locked for
 * 15 minutes; a successful login resets the counter. Distinct from the sliding-window rate limiters
 * (AC-4.1) — this is per-account state, not per-(IP+email)/IP throughput.
 *
 * Backed by the same Redis-backed `cache.rate_limiter` pool the rate limiters already use (shared
 * across app instances, D-54's same reasoning), keyed by a digest of the email so the cache key
 * itself never carries a plaintext identifier.
 */
final readonly class AdminLockoutTracker
{
    private const int MAX_CONSECUTIVE_FAILURES = 10;
    private const int LOCKOUT_SECONDS = 15 * 60;
    private const int FAILURE_COUNTER_TTL_SECONDS = 15 * 60;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function isLocked(string $email): bool
    {
        $item = $this->cache->getItem($this->lockKey($email));

        return $item->isHit();
    }

    /** Seconds remaining, or 0 if not locked. */
    public function remainingLockSeconds(string $email): int
    {
        $item = $this->cache->getItem($this->lockKey($email));
        if (!$item->isHit()) {
            return 0;
        }

        $lockedUntil = $item->get();

        return \is_int($lockedUntil) ? max(0, $lockedUntil - time()) : 0;
    }

    /** @return bool true if this failure just triggered a new lockout */
    public function recordFailure(string $email): bool
    {
        $countItem = $this->cache->getItem($this->countKey($email));
        $count = ($countItem->isHit() && \is_int($countItem->get())) ? $countItem->get() : 0;
        ++$count;

        $countItem->set($count);
        $countItem->expiresAfter(self::FAILURE_COUNTER_TTL_SECONDS);
        $this->cache->save($countItem);

        if ($count >= self::MAX_CONSECUTIVE_FAILURES) {
            $lockedUntil = time() + self::LOCKOUT_SECONDS;
            $lockItem = $this->cache->getItem($this->lockKey($email));
            $lockItem->set($lockedUntil);
            $lockItem->expiresAfter(self::LOCKOUT_SECONDS);
            $this->cache->save($lockItem);

            return true;
        }

        return false;
    }

    public function recordSuccess(string $email): void
    {
        $this->cache->deleteItem($this->countKey($email));
        $this->cache->deleteItem($this->lockKey($email));
    }

    private function countKey(string $email): string
    {
        return 'admin_lockout_count_'.hash('sha256', strtolower($email));
    }

    private function lockKey(string $email): string
    {
        return 'admin_lockout_locked_'.hash('sha256', strtolower($email));
    }
}
