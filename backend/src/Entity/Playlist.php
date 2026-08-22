<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The generated result of one `PlaylistGenerationJob` (spec 13 §2, spec 14 §1). `$creationAttemptedAt`
 * + `$providerPlaylistId` together are the level-2 idempotency creation marker (D-136); do not create
 * against the provider when `$creationAttemptedAt` is already set. `$insertedThroughOrdinal` is the
 * level-3 insertion watermark (D-137).
 *
 * Deleting this row (`DELETE /api/playlists/{id}`) never deletes the provider-side playlist — the
 * port has no delete method and D-71 keeps it frozen at nine (D-151).
 */
#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'playlists')]
#[ORM\Index(name: 'idx_playlists_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_playlists_concert', columns: ['concert_id'])]
#[ORM\Index(name: 'idx_playlists_owner_concert', columns: ['owner_id', 'concert_id'])]
#[ORM\Index(name: 'idx_playlists_job', columns: ['job_id'])]
class Playlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Concert::class)]
    #[ORM\JoinColumn(name: 'concert_id', nullable: false, onDelete: 'CASCADE')]
    private Concert $concert;

    #[ORM\ManyToOne(targetEntity: PlaylistGenerationJob::class)]
    #[ORM\JoinColumn(name: 'job_id', nullable: false, onDelete: 'CASCADE')]
    private PlaylistGenerationJob $job;

    #[ORM\Column(name: 'provider_key', type: 'string', length: 32)]
    private string $providerKey;

    /** The creation marker (D-136). NULL until `createPlaylist()` is confirmed. */
    #[ORM\Column(name: 'provider_playlist_id', type: 'string', length: 128, nullable: true)]
    private ?string $providerPlaylistId = null;

    /** Written and committed BEFORE `createPlaylist()` is called. */
    #[ORM\Column(name: 'creation_attempted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $creationAttemptedAt = null;

    #[ORM\Column(name: 'external_url', type: 'text', nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** The insertion watermark (D-137). */
    #[ORM\Column(name: 'inserted_through_ordinal', type: 'integer')]
    private int $insertedThroughOrdinal = 0;

    /** @var array<int, array{code: string, params: array<string, mixed>}> Job-level report codes (D-141). */
    #[ORM\Column(name: 'report_summary', type: 'json')]
    private array $reportSummary = [];

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PlaylistTrack> */
    #[ORM\OneToMany(targetEntity: PlaylistTrack::class, mappedBy: 'playlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['ordinal' => 'ASC'])]
    private Collection $tracks;

    public function __construct(
        User $owner,
        Concert $concert,
        PlaylistGenerationJob $job,
        string $providerKey,
        string $name,
        \DateTimeImmutable $now,
    ) {
        $this->owner = $owner;
        $this->concert = $concert;
        $this->job = $job;
        $this->providerKey = $providerKey;
        $this->name = $name;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->tracks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getConcert(): Concert
    {
        return $this->concert;
    }

    public function getJob(): PlaylistGenerationJob
    {
        return $this->job;
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getProviderPlaylistId(): ?string
    {
        return $this->providerPlaylistId;
    }

    public function getCreationAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->creationAttemptedAt;
    }

    public function markCreationAttempted(\DateTimeImmutable $now): void
    {
        $this->creationAttemptedAt = $now;
        $this->updatedAt = $now;
    }

    public function confirmCreated(string $providerPlaylistId, string $externalUrl, \DateTimeImmutable $now): void
    {
        $this->providerPlaylistId = $providerPlaylistId;
        $this->externalUrl = $externalUrl;
        $this->updatedAt = $now;
    }

    /** F-14 recovery: `create-anyway` clears the marker so a fresh attempt is made. */
    public function clearCreationMarker(\DateTimeImmutable $now): void
    {
        $this->creationAttemptedAt = null;
        $this->updatedAt = $now;
    }

    public function isCreationIndeterminate(): bool
    {
        return null !== $this->creationAttemptedAt && null === $this->providerPlaylistId;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description, \DateTimeImmutable $now): void
    {
        $this->description = $description;
        $this->updatedAt = $now;
    }

    public function getInsertedThroughOrdinal(): int
    {
        return $this->insertedThroughOrdinal;
    }

    public function advanceInsertionWatermark(int $ordinal, \DateTimeImmutable $now): void
    {
        $this->insertedThroughOrdinal = $ordinal;
        $this->updatedAt = $now;
    }

    /** @return array<int, array{code: string, params: array<string, mixed>}> */
    public function getReportSummary(): array
    {
        return $this->reportSummary;
    }

    /** @param array<string, mixed> $params */
    public function addReportEntry(string $code, array $params, \DateTimeImmutable $now): void
    {
        $this->reportSummary[] = ['code' => $code, 'params' => $params];
        $this->updatedAt = $now;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, PlaylistTrack> */
    public function getTracks(): Collection
    {
        return $this->tracks;
    }

    public function addTrack(PlaylistTrack $track): void
    {
        $this->tracks->add($track);
    }
}
