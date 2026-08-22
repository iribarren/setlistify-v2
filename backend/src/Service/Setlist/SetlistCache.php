<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use App\Entity\SetlistCacheEntry;
use App\Repository\SetlistCacheEntryRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The three-tier read (US-6, `docs/architecture.md` §5): Redis (short TTL, session-scoped) →
 * PostgreSQL `setlist_cache` (durable, D-59/D-60) → setlist.fm, through {@see SetlistFmClient} —
 * the only class in the app allowed to hold a reference to it (D-58).
 *
 * A durable-tier hit **promotes** into Redis (AC-6.2) so a second read inside the same session
 * never touches PostgreSQL either. A miss that reaches setlist.fm writes both tiers before
 * returning (AC-6.3). Concurrent misses for the same key are serialized by a `symfony/lock`
 * (AC-6.7) so only one of them actually calls out.
 */
final class SetlistCache
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly SetlistCacheEntryRepository $repository,
        private readonly SetlistFmClient $client,
        private readonly SetlistCacheMetrics $metrics,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly int $cacheTtl,
        private readonly float $tokenWaitSeconds,
    ) {
    }

    public function fetchArtistSearch(string $name): CachedFetch
    {
        return $this->fetch(
            endpoint: 'artist.search',
            params: ['artistName' => $name],
            path: '/search/artists',
            staleAfterFor: fn (\DateTimeImmutable $now): \DateTimeImmutable => $now->modify('+1 day'),
        );
    }

    /** Page 1 can gain entries (D-59) — volatile; every later page is history and immutable. */
    public function fetchArtistSetlistsPage(string $mbid, int $page, ?float $waitOverrideSeconds = null): CachedFetch
    {
        return $this->fetch(
            endpoint: 'artist.setlists',
            params: ['mbid' => $mbid, 'p' => $page],
            path: \sprintf('/artist/%s/setlists', $mbid),
            staleAfterFor: 1 === $page
                ? fn (\DateTimeImmutable $now): \DateTimeImmutable => $now->modify('+1 day')
                : fn (\DateTimeImmutable $now): ?\DateTimeImmutable => null,
            waitOverrideSeconds: $waitOverrideSeconds,
        );
    }

    /** A specific past setlist never changes (D-59) — immutable forever once fetched (AC-4.5). */
    public function fetchSetlistDetail(string $setlistfmId): CachedFetch
    {
        return $this->fetch(
            endpoint: 'setlist.get',
            params: ['id' => $setlistfmId],
            path: \sprintf('/setlist/%s', $setlistfmId),
            staleAfterFor: fn (\DateTimeImmutable $now): ?\DateTimeImmutable => null,
        );
    }

    /**
     * @param array<string, scalar>                             $params
     * @param \Closure(\DateTimeImmutable): ?\DateTimeImmutable $staleAfterFor
     */
    private function fetch(
        string $endpoint,
        array $params,
        string $path,
        \Closure $staleAfterFor,
        ?float $waitOverrideSeconds = null,
    ): CachedFetch {
        $cacheKey = $this->buildCacheKey($endpoint, $params);

        $redisHit = $this->readRedis($cacheKey);
        if (null !== $redisHit) {
            $this->metrics->recordHit('redis');

            return $redisHit;
        }

        $lock = $this->lockFactory->createLock('setlistfm:fetch:'.$cacheKey, ttl: 15.0);
        $acquired = $lock->acquire(false);
        $waited = 0.0;
        while (!$acquired && $waited < $this->tokenWaitSeconds) {
            usleep(50_000);
            $waited += 0.05;
            $acquired = $lock->acquire(false);
        }

        try {
            // Re-check Redis: another process may have just filled it while we waited (AC-6.7).
            $redisHit = $this->readRedis($cacheKey);
            if (null !== $redisHit) {
                $this->metrics->recordHit('redis');

                return $redisHit;
            }

            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $entry = $this->repository->findOneByCacheKey($cacheKey);

            if (null !== $entry && !$entry->isStale($now)) {
                $this->metrics->recordHit('postgres');
                $this->writeRedis($cacheKey, $entry->getPayload(), $entry->getFetchedAt());

                return CachedFetch::cacheHit($entry->getPayload(), $entry->getFetchedAt());
            }

            $result = $this->client->request($endpoint, $path, $params, $waitOverrideSeconds);
            $this->metrics->recordHit('outbound');

            if ($result->notFound) {
                return CachedFetch::notFoundResult();
            }

            if ($result->degraded) {
                \assert(null !== $result->degradedReason);
                if (null !== $entry) {
                    return CachedFetch::staleCache($entry->getPayload(), $entry->getFetchedAt(), $result->degradedReason, $result->budgetResetAt);
                }

                return CachedFetch::unavailable($result->degradedReason, $result->budgetResetAt);
            }

            \assert($result->success && null !== $result->payload && null !== $result->httpStatus);
            $staleAfter = $staleAfterFor($now);
            $this->repository->save(new SetlistCacheEntry($cacheKey, $endpoint, $result->payload, $now, $staleAfter, $result->httpStatus));
            $this->writeRedis($cacheKey, $result->payload, $now);

            return CachedFetch::live($result->payload, $now);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    /** @param array<string, scalar> $params */
    private function buildCacheKey(string $endpoint, array $params): string
    {
        ksort($params);

        return $endpoint.':'.md5(http_build_query($params));
    }

    private function readRedis(string $cacheKey): ?CachedFetch
    {
        try {
            $raw = $this->redis->get($this->redisKey($cacheKey));
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !\is_array($decoded['payload'] ?? null) || !\is_string($decoded['fetchedAt'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded['payload'];

        return CachedFetch::cacheHit($payload, new \DateTimeImmutable($decoded['fetchedAt']));
    }

    /** @param array<string, mixed> $payload */
    private function writeRedis(string $cacheKey, array $payload, \DateTimeImmutable $fetchedAt): void
    {
        try {
            $this->redis->setex(
                $this->redisKey($cacheKey),
                max(1, $this->cacheTtl),
                json_encode(['payload' => $payload, 'fetchedAt' => $fetchedAt->format(\DateTimeInterface::ATOM)], \JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            // Tier 1 is an accelerator, not a source of truth — a write failure here must not
            // break a read that already has a good answer from tier 2/3.
        }
    }

    private function redisKey(string $cacheKey): string
    {
        return 'setlistfm:cache:'.$cacheKey;
    }
}
