<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlaylistTrackRepository;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use Doctrine\ORM\Mapping as ORM;

/**
 * One source song's row in a `Playlist` (spec 13 §2, spec 14 §2/§4). Every song in the selected
 * setlist gets a row here, including the ones that produce no track (D-139) — that is what makes
 * partial success storable and the report honest. `$ordinal` is the position in *our* ordered list;
 * `$sourcePosition` is the position in the show; the divergence between the two is the report.
 */
#[ORM\Entity(repositoryClass: PlaylistTrackRepository::class)]
#[ORM\Table(name: 'playlist_tracks')]
#[ORM\UniqueConstraint(name: 'uniq_playlist_track_ordinal', columns: ['playlist_id', 'ordinal'])]
#[ORM\Index(name: 'idx_playlist_tracks_source', columns: ['playlist_id', 'source_position'])]
#[ORM\Index(name: 'idx_playlist_tracks_song', columns: ['source_song_id'])]
#[ORM\Index(name: 'idx_playlist_tracks_band', columns: ['source_band_id'])]
class PlaylistTrack
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Playlist::class, inversedBy: 'tracks')]
    #[ORM\JoinColumn(name: 'playlist_id', nullable: false, onDelete: 'CASCADE')]
    private Playlist $playlist;

    /** Dense, 0-based position among all source songs, in playing order. */
    #[ORM\Column(type: 'integer')]
    private int $ordinal;

    /** A purged setlist must not delete the report — `ON DELETE SET NULL`. */
    #[ORM\ManyToOne(targetEntity: Song::class)]
    #[ORM\JoinColumn(name: 'source_song_id', nullable: true, onDelete: 'SET NULL')]
    private ?Song $sourceSong = null;

    /** Denormalized for multi-band ordering and the report. */
    #[ORM\ManyToOne(targetEntity: Band::class)]
    #[ORM\JoinColumn(name: 'source_band_id', nullable: false)]
    private Band $sourceBand;

    /** Denormalized, survives a cache purge. */
    #[ORM\Column(name: 'source_setlistfm_id', type: 'string', length: 64)]
    private string $sourceSetlistfmId;

    /** `Song::$position`, preserved even when unmatched (D-140). */
    #[ORM\Column(name: 'source_position', type: 'integer')]
    private int $sourcePosition;

    /** Denormalized, so the report is readable after a purge. */
    #[ORM\Column(name: 'source_title', type: 'string', length: 200)]
    private string $sourceTitle;

    /** Medley segments (D-114). */
    #[ORM\Column(name: 'segment_index', type: 'smallint', nullable: true)]
    private ?int $segmentIndex = null;

    #[ORM\Column(name: 'provider_track_id', type: 'string', length: 128, nullable: true)]
    private ?string $providerTrackId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(type: 'string', length: 32, enumType: TrackOutcome::class)]
    private TrackOutcome $outcome;

    #[ORM\Column(name: 'reason_code', type: 'string', length: 48, enumType: ReportCode::class, nullable: true)]
    private ?ReportCode $reasonCode = null;

    /** @var array<string, mixed>|null e.g. `{"artist": "Nine Inch Nails"}`. */
    #[ORM\Column(name: 'reason_params', type: 'json', nullable: true)]
    private ?array $reasonParams = null;

    /** NULL until this row's insertion batch is confirmed (D-137). */
    #[ORM\Column(name: 'inserted_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $insertedAt = null;

    public function __construct(
        Playlist $playlist,
        int $ordinal,
        ?Song $sourceSong,
        Band $sourceBand,
        string $sourceSetlistfmId,
        int $sourcePosition,
        string $sourceTitle,
        ?int $segmentIndex = null,
    ) {
        $this->playlist = $playlist;
        $this->ordinal = $ordinal;
        $this->sourceSong = $sourceSong;
        $this->sourceBand = $sourceBand;
        $this->sourceSetlistfmId = $sourceSetlistfmId;
        $this->sourcePosition = $sourcePosition;
        $this->sourceTitle = $sourceTitle;
        $this->segmentIndex = $segmentIndex;
        $this->outcome = TrackOutcome::Pending;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getOrdinal(): int
    {
        return $this->ordinal;
    }

    public function getSourceSong(): ?Song
    {
        return $this->sourceSong;
    }

    public function getSourceBand(): Band
    {
        return $this->sourceBand;
    }

    public function getSourceSetlistfmId(): string
    {
        return $this->sourceSetlistfmId;
    }

    public function getSourcePosition(): int
    {
        return $this->sourcePosition;
    }

    public function getSourceTitle(): string
    {
        return $this->sourceTitle;
    }

    public function getSegmentIndex(): ?int
    {
        return $this->segmentIndex;
    }

    public function getProviderTrackId(): ?string
    {
        return $this->providerTrackId;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function getOutcome(): TrackOutcome
    {
        return $this->outcome;
    }

    public function getReasonCode(): ?ReportCode
    {
        return $this->reasonCode;
    }

    /** @return array<string, mixed>|null */
    public function getReasonParams(): ?array
    {
        return $this->reasonParams;
    }

    /** @param array<string, mixed>|null $reasonParams */
    public function resolve(
        TrackOutcome $outcome,
        ?string $providerTrackId,
        ?float $confidence,
        ?ReportCode $reasonCode,
        ?array $reasonParams,
    ): void {
        $this->outcome = $outcome;
        $this->providerTrackId = $providerTrackId;
        $this->confidence = $confidence;
        $this->reasonCode = $reasonCode;
        $this->reasonParams = $reasonParams;
    }

    /**
     * Spec 13 §6's staleness-on-resume row 1: `Choice\StalenessReconciler` calls this on every row
     * whose source song's title changed since selection (a setlist.fm correction) — updates the
     * denormalized title and puts the row back to `Pending` so `MatchingStage`'s own resume loop
     * re-matches it exactly like a never-attempted song. A row whose title did NOT change is never
     * touched by this method, which is what "keep every user choice whose song is unchanged" means
     * in practice: nothing here resets it, so whatever it already resolved to (or is still deciding)
     * stands.
     */
    public function resetForStalenessReconciliation(string $currentTitle): void
    {
        $this->sourceTitle = $currentTitle;
        $this->outcome = TrackOutcome::Pending;
        $this->providerTrackId = null;
        $this->confidence = null;
        $this->reasonCode = null;
        $this->reasonParams = null;
    }

    public function getInsertedAt(): ?\DateTimeImmutable
    {
        return $this->insertedAt;
    }

    public function markInserted(\DateTimeImmutable $now): void
    {
        $this->insertedAt = $now;
    }
}
