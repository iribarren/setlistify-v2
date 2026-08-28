<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SetlistCacheEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The durable cache tier's source of truth (D-59, D-60): a verbatim setlist.fm response, keyed by
 * the canonical outbound request. `$payload` is the receipt — retained so a future change to
 * `App\Service\Setlist\SetlistNormalizer` can re-derive `Setlist`/`Song` rows without re-spending
 * budget (AC-4.6, D-60).
 *
 * `$staleAfter` is the freshness class (D-59): `null` means immutable — never re-fetched. A
 * non-null instant marks volatile data (an artist search, or the first page of a band's setlist
 * index) that may legitimately gain entries and is eligible for re-fetch once passed.
 */
#[ORM\Entity(repositoryClass: SetlistCacheEntryRepository::class)]
#[ORM\Table(name: 'setlist_cache')]
#[ORM\UniqueConstraint(name: 'uniq_setlist_cache_key', columns: ['cache_key'])]
#[ORM\Index(name: 'idx_setlist_cache_endpoint', columns: ['endpoint'])]
class SetlistCacheEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Endpoint + canonical sorted params (AC-6.6) — never a caller-supplied string. */
    #[ORM\Column(name: 'cache_key', type: 'string', length: 255)]
    private string $cacheKey;

    /** One of 'artist.search' | 'artist.get' | 'artist.setlists' | 'setlist.get'. */
    #[ORM\Column(type: 'string', length: 32)]
    private string $endpoint;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'fetched_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(name: 'stale_after', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $staleAfter;

    #[ORM\Column(name: 'http_status', type: 'smallint')]
    private int $httpStatus;

    /** @param array<string, mixed> $payload */
    public function __construct(
        string $cacheKey,
        string $endpoint,
        array $payload,
        \DateTimeImmutable $fetchedAt,
        ?\DateTimeImmutable $staleAfter,
        int $httpStatus,
    ) {
        $this->cacheKey = $cacheKey;
        $this->endpoint = $endpoint;
        $this->payload = $payload;
        $this->fetchedAt = $fetchedAt;
        $this->staleAfter = $staleAfter;
        $this->httpStatus = $httpStatus;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getStaleAfter(): ?\DateTimeImmutable
    {
        return $this->staleAfter;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** D-59: null staleAfter means immutable — never stale. */
    public function isStale(\DateTimeImmutable $now): bool
    {
        return null !== $this->staleAfter && $this->staleAfter <= $now;
    }

    /**
     * Re-fetching a re-fetchable (non-immutable) cache key — a volatile entry past its
     * `staleAfter`, or a forced-live re-check (docs/specs/2026-08-27-instant-setlist-refresh.md,
     * D-263) — overwrites this SAME row in place rather than inserting a second row under the same
     * unique `cache_key` (`uniq_setlist_cache_key`).
     *
     * @param array<string, mixed> $payload
     */
    public function refresh(array $payload, \DateTimeImmutable $fetchedAt, ?\DateTimeImmutable $staleAfter, int $httpStatus): void
    {
        $this->payload = $payload;
        $this->fetchedAt = $fetchedAt;
        $this->staleAfter = $staleAfter;
        $this->httpStatus = $httpStatus;
    }
}
