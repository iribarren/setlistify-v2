<?php

declare(strict_types=1);

namespace App\Service\Matching\Cache;

use App\Entity\TrackResolution;
use App\Repository\TrackResolutionRepository;
use Psr\Clock\ClockInterface;

/**
 * Redis read-through over the `track_resolutions` table (spec 12 §8, D-121) — the same two-tier
 * shape `App\Service\Setlist\SetlistCache` already uses, for the same reason: PostgreSQL is the
 * source of truth for a fact that is expensive to produce and cheap to keep (a resolution can have
 * spent real provider budget), and Redis absorbs repeats within and across nearby generations.
 *
 * Cache key: `provider | algorithmVersion | normalizedArtist | normalizedTitle`. `market`/region is
 * deliberately excluded — which recording a title resolves to does not depend on where the asker
 * stands (spec 12 §8); availability is discovered per-user at insert time instead.
 *
 * TTLs: 180 days for a `matched` resolution, 60 for `matched_low_confidence`, 30 for a cached
 * negative (`not_found`) — catalogs gain songs, so a shorter TTL is how a newly-added track is
 * eventually re-searched. The Redis front tier holds every outcome for 300 s, matching
 * `SETLISTFM_CACHE_TTL`'s posture: its only job is absorbing repeats within one generation's burst.
 */
final class TrackResolutionStore
{
    private const int REDIS_TTL_SECONDS = 300;

    private const array TTL_DAYS = [
        'matched' => 180,
        'matched_low_confidence' => 60,
        'not_found' => 30,
    ];

    public function __construct(
        private readonly \Redis $redis,
        private readonly TrackResolutionRepository $repository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function find(string $provider, int $algorithmVersion, string $normalizedArtist, string $normalizedTitle): ?ResolvedTrack
    {
        $cacheKey = self::cacheKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);

        $redisHit = $this->readRedis($cacheKey);
        if (null !== $redisHit) {
            return $redisHit;
        }

        $entity = $this->repository->findOneByKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);
        if (null === $entity) {
            return null;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if ($entity->isExpired($now)) {
            return null;
        }

        $resolved = self::fromEntity($entity);
        $this->writeRedis($cacheKey, $resolved); // Durable-tier hit promotes into Redis.

        return $resolved;
    }

    /**
     * Tier 7: persist a resolution, positive or negative alike. Replaces any existing row under the
     * same key (an `algorithmVersion` bump is the normal reason one would already exist).
     *
     * @param 'matched'|'matched_low_confidence'|'not_found' $outcome
     * @param list<array<string, mixed>>                     $candidatesDigest
     */
    public function save(
        string $provider,
        int $algorithmVersion,
        string $normalizedArtist,
        string $normalizedTitle,
        ?string $providerTrackId,
        float $confidence,
        string $outcome,
        array $candidatesDigest,
    ): ResolvedTrack {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $expiresAt = $now->modify(\sprintf('+%d days', self::TTL_DAYS[$outcome]));

        $existing = $this->repository->findOneByKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);
        if (null !== $existing) {
            $this->repository->delete($existing);
        }

        $entity = new TrackResolution(
            $provider,
            $algorithmVersion,
            $normalizedTitle,
            $normalizedArtist,
            $providerTrackId,
            $confidence,
            $outcome,
            $candidatesDigest,
            $now,
            $expiresAt,
        );
        $this->repository->save($entity);

        $resolved = self::fromEntity($entity);
        $this->writeRedis(self::cacheKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle), $resolved);

        return $resolved;
    }

    /**
     * F-13 / spec 12 §8's one required runtime invalidation: a `NotFoundException` at insert time
     * means the provider track id no longer exists. Delete the row so the next generation re-resolves
     * it — a durable positive is never left pointing at a vanished track.
     */
    public function delete(string $provider, int $algorithmVersion, string $normalizedArtist, string $normalizedTitle): void
    {
        $entity = $this->repository->findOneByKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);
        if (null !== $entity) {
            $this->repository->delete($entity);
        }

        try {
            $this->redis->del($this->redisKey(self::cacheKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle)));
        } catch (\Throwable) {
            // Redis is an accelerator; a failed delete there is corrected by the next TTL expiry or
            // the next `save()`, and must never block the caller from reporting the vanished track.
        }
    }

    private static function cacheKey(string $provider, int $algorithmVersion, string $normalizedArtist, string $normalizedTitle): string
    {
        return \sprintf('%s|%d|%s|%s', $provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);
    }

    private function readRedis(string $cacheKey): ?ResolvedTrack
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
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return ResolvedTrack::fromArray($decoded);
    }

    private function writeRedis(string $cacheKey, ResolvedTrack $resolved): void
    {
        try {
            $this->redis->setex(
                $this->redisKey($cacheKey),
                self::REDIS_TTL_SECONDS,
                json_encode($resolved->toArray(), \JSON_THROW_ON_ERROR),
            );
        } catch (\Throwable) {
            // Tier 1 is an accelerator, not a source of truth — see SetlistCache's identical posture.
        }
    }

    private function redisKey(string $cacheKey): string
    {
        return 'matching:resolution:'.$cacheKey;
    }

    private static function fromEntity(TrackResolution $entity): ResolvedTrack
    {
        return new ResolvedTrack(
            provider: $entity->getProvider(),
            algorithmVersion: $entity->getAlgorithmVersion(),
            normalizedArtist: $entity->getNormalizedArtist(),
            normalizedTitle: $entity->getNormalizedTitle(),
            providerTrackId: $entity->getProviderTrackId(),
            confidence: $entity->getConfidence(),
            outcome: $entity->getOutcome(),
            candidatesDigest: $entity->getCandidatesDigest(),
            resolvedAt: $entity->getResolvedAt(),
            expiresAt: $entity->getExpiresAt(),
        );
    }
}
