<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserTrackPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A per-user override of `TrackResolution` (D-198, docs/specs/2026-08-25-playlist-normal-mode.md).
 * Keyed exactly like `TrackResolution` plus `owner`, so the two tables answer the same question at
 * two scopes. **Never written by anything but `App\Service\Playlist\Choice\PreferenceRecorder`, and
 * never mutates or is written into `TrackResolution`** (AC-5.5) — one user's taste must not leak into
 * the global cache. `Song`/`Band` FKs are deliberately absent: a chosen version holds across concerts.
 *
 * User-scoped: a cross-owner lookup must 404, not 403 (D-27 shape) — see
 * `App\Security\UserTrackPreferenceOwnerExtension`. No preference-management endpoint exists (Q-3);
 * this table is read only by `App\Service\Playlist\Stage\MatchingStage` via the recorder.
 */
#[ORM\Entity(repositoryClass: UserTrackPreferenceRepository::class)]
#[ORM\Table(name: 'user_track_preferences')]
#[ORM\UniqueConstraint(name: 'uniq_user_track_preference', columns: ['owner_id', 'provider', 'algorithm_version', 'normalized_artist', 'normalized_title'])]
#[ORM\Index(name: 'idx_user_track_preferences_owner', columns: ['owner_id'])]
class UserTrackPreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** `StreamingProviderInterface::key()`, a runtime string. */
    #[ORM\Column(type: 'string', length: 32)]
    private string $provider;

    /** The same invalidation lever as `TrackResolution::$algorithmVersion` (D-121). */
    #[ORM\Column(name: 'algorithm_version', type: 'smallint')]
    private int $algorithmVersion;

    /** `App\Service\Concert\BandResolver::normalize()` of the EXPECTED artist. */
    #[ORM\Column(name: 'normalized_artist', type: 'string', length: 200)]
    private string $normalizedArtist;

    /** `App\Service\Matching\SongNormalizer::normalize()->comparisonCore`. */
    #[ORM\Column(name: 'normalized_title', type: 'string', length: 200)]
    private string $normalizedTitle;

    /** What the user actually chose. Never null. */
    #[ORM\Column(name: 'provider_track_id', type: 'string', length: 128)]
    private string $providerTrackId;

    #[ORM\Column(name: 'chosen_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $chosenAt;

    #[ORM\Column(name: 'used_count', type: 'integer')]
    private int $usedCount = 0;

    public function __construct(
        User $owner,
        string $provider,
        int $algorithmVersion,
        string $normalizedArtist,
        string $normalizedTitle,
        string $providerTrackId,
        \DateTimeImmutable $now,
    ) {
        $this->owner = $owner;
        $this->provider = $provider;
        $this->algorithmVersion = $algorithmVersion;
        $this->normalizedArtist = $normalizedArtist;
        $this->normalizedTitle = $normalizedTitle;
        $this->providerTrackId = $providerTrackId;
        $this->chosenAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getAlgorithmVersion(): int
    {
        return $this->algorithmVersion;
    }

    public function getNormalizedArtist(): string
    {
        return $this->normalizedArtist;
    }

    public function getNormalizedTitle(): string
    {
        return $this->normalizedTitle;
    }

    public function getProviderTrackId(): string
    {
        return $this->providerTrackId;
    }

    public function getChosenAt(): \DateTimeImmutable
    {
        return $this->chosenAt;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    /** A fresh explicit choice by the user (a submitted version choice) — resets the usage counter. */
    public function choose(string $providerTrackId, \DateTimeImmutable $now): void
    {
        $this->providerTrackId = $providerTrackId;
        $this->chosenAt = $now;
        $this->usedCount = 0;
    }

    /** `MatchingStage` applied this preference to auto-resolve a song (D-199 — never silent). */
    public function recordUsage(): void
    {
        ++$this->usedCount;
    }
}
