<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SetlistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The queryable projection of one cached setlist.fm setlist (D-60) — one specific show. Paired
 * with a `SetlistCacheEntry` (endpoint `setlist.get`) that holds the verbatim payload this row was
 * derived from. Reference data, shared across every user (D-66) — never user-scoped.
 */
#[ORM\Entity(repositoryClass: SetlistRepository::class)]
#[ORM\Table(name: 'setlists')]
#[ORM\UniqueConstraint(name: 'uniq_setlists_setlistfm_id', columns: ['setlistfm_id'])]
#[ORM\Index(name: 'idx_setlists_band_event_date', columns: ['band_id', 'event_date'])]
class Setlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'setlistfm_id', type: 'string', length: 32)]
    private string $setlistfmId;

    #[ORM\ManyToOne(targetEntity: Band::class)]
    #[ORM\JoinColumn(name: 'band_id', nullable: false)]
    private Band $band;

    #[ORM\Column(name: 'event_date', type: 'date_immutable')]
    private \DateTimeImmutable $eventDate;

    #[ORM\Column(name: 'venue_name', type: 'string', length: 200, nullable: true)]
    private ?string $venueName = null;

    #[ORM\Column(name: 'venue_city', type: 'string', length: 200, nullable: true)]
    private ?string $venueCity = null;

    #[ORM\Column(name: 'venue_country', type: 'string', length: 2, nullable: true)]
    private ?string $venueCountry = null;

    #[ORM\Column(name: 'tour_name', type: 'string', length: 200, nullable: true)]
    private ?string $tourName = null;

    #[ORM\Column(name: 'song_count', type: 'smallint')]
    private int $songCount = 0;

    #[ORM\Column(name: 'is_empty', type: 'boolean')]
    private bool $isEmpty = false;

    #[ORM\Column(name: 'fetched_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $fetchedAt;

    /**
     * The setlist.fm canonical page for this show (D-186) — the payload's own `url` field, slug-based
     * (`https://www.setlist.fm/setlist/<artist>/<year>/<venue-city>-<id>.html`); the id-only form
     * 404s. `null` for any row cached before this column existed — never backfilled (D-59).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $url = null;

    /** @var Collection<int, Song> */
    #[ORM\OneToMany(targetEntity: Song::class, mappedBy: 'setlist', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $songs;

    public function __construct(
        string $setlistfmId,
        Band $band,
        \DateTimeImmutable $eventDate,
        ?string $venueName,
        ?string $venueCity,
        ?string $venueCountry,
        ?string $tourName,
        \DateTimeImmutable $fetchedAt,
        ?string $url = null,
    ) {
        $this->setlistfmId = $setlistfmId;
        $this->band = $band;
        $this->eventDate = $eventDate;
        $this->venueName = $venueName;
        $this->venueCity = $venueCity;
        $this->venueCountry = $venueCountry;
        $this->tourName = $tourName;
        $this->fetchedAt = $fetchedAt;
        $this->url = $url;
        $this->songs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSetlistfmId(): string
    {
        return $this->setlistfmId;
    }

    public function getBand(): Band
    {
        return $this->band;
    }

    public function getEventDate(): \DateTimeImmutable
    {
        return $this->eventDate;
    }

    public function getVenueName(): ?string
    {
        return $this->venueName;
    }

    public function getVenueCity(): ?string
    {
        return $this->venueCity;
    }

    public function getVenueCountry(): ?string
    {
        return $this->venueCountry;
    }

    public function getTourName(): ?string
    {
        return $this->tourName;
    }

    public function getSongCount(): int
    {
        return $this->songCount;
    }

    public function isEmpty(): bool
    {
        return $this->isEmpty;
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /** @return Collection<int, Song> */
    public function getSongs(): Collection
    {
        return $this->songs;
    }

    public function addSong(Song $song): void
    {
        $this->songs->add($song);
        $this->songCount = $this->songs->count();
        $this->isEmpty = 0 === $this->songCount;
    }

    /** AC-4.4: an explicit, distinguishable "fetched, and it really has no songs" state. */
    public function markEmpty(): void
    {
        $this->songCount = 0;
        $this->isEmpty = true;
    }
}
