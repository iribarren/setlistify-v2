<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrackResolutionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The durable half of `TrackResolutionStore`'s cache (spec 12 §8, spec 14 §2). Keyed by
 * `(provider, algorithmVersion, normalizedArtist, normalizedTitle)` — `market`/region is
 * deliberately excluded from the key: which recording *is* the song does not depend on where the
 * asker stands. `providerTrackId === null` is a cached negative result, not "not yet resolved".
 */
#[ORM\Entity(repositoryClass: TrackResolutionRepository::class)]
#[ORM\Table(name: 'track_resolutions')]
#[ORM\UniqueConstraint(name: 'uniq_track_resolution', columns: ['provider', 'algorithm_version', 'normalized_artist', 'normalized_title'])]
#[ORM\Index(name: 'idx_track_resolutions_expires', columns: ['expires_at'])]
class TrackResolution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $provider;

    #[ORM\Column(name: 'algorithm_version', type: 'smallint')]
    private int $algorithmVersion;

    #[ORM\Column(name: 'normalized_title', type: 'string', length: 200)]
    private string $normalizedTitle;

    #[ORM\Column(name: 'normalized_artist', type: 'string', length: 200)]
    private string $normalizedArtist;

    /** NULL = a cached negative result. */
    #[ORM\Column(name: 'provider_track_id', type: 'string', length: 128, nullable: true)]
    private ?string $providerTrackId = null;

    #[ORM\Column(type: 'float')]
    private float $confidence;

    /** @var 'matched'|'matched_low_confidence'|'not_found' */
    #[ORM\Column(type: 'string', length: 32)]
    private string $outcome;

    /** @var list<array<string, mixed>> Top 5 candidates + sub-scores, for the harness and the backoffice. */
    #[ORM\Column(name: 'candidates_digest', type: 'json')]
    private array $candidatesDigest;

    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $resolvedAt;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param 'matched'|'matched_low_confidence'|'not_found' $outcome
     * @param list<array<string, mixed>>                     $candidatesDigest
     */
    public function __construct(
        string $provider,
        int $algorithmVersion,
        string $normalizedTitle,
        string $normalizedArtist,
        ?string $providerTrackId,
        float $confidence,
        string $outcome,
        array $candidatesDigest,
        \DateTimeImmutable $resolvedAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->provider = $provider;
        $this->algorithmVersion = $algorithmVersion;
        $this->normalizedTitle = $normalizedTitle;
        $this->normalizedArtist = $normalizedArtist;
        $this->providerTrackId = $providerTrackId;
        $this->confidence = $confidence;
        $this->outcome = $outcome;
        $this->candidatesDigest = $candidatesDigest;
        $this->resolvedAt = $resolvedAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getAlgorithmVersion(): int
    {
        return $this->algorithmVersion;
    }

    public function getNormalizedTitle(): string
    {
        return $this->normalizedTitle;
    }

    public function getNormalizedArtist(): string
    {
        return $this->normalizedArtist;
    }

    public function getProviderTrackId(): ?string
    {
        return $this->providerTrackId;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /** @return 'matched'|'matched_low_confidence'|'not_found' */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /** @return list<array<string, mixed>> */
    public function getCandidatesDigest(): array
    {
        return $this->candidatesDigest;
    }

    public function getResolvedAt(): \DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
