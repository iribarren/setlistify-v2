<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SongRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One song within a `Setlist`, in playing order (AC-4.1, AC-4.2, AC-4.3). Nothing here is filtered
 * — a tape intro is stored exactly as setlist.fm marked it; prompt 12 decides what a song-matcher
 * does with `$isTape`.
 */
#[ORM\Entity(repositoryClass: SongRepository::class)]
#[ORM\Table(name: 'songs')]
#[ORM\UniqueConstraint(name: 'uniq_songs_setlist_position', columns: ['setlist_id', 'position'])]
class Song
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Setlist::class, inversedBy: 'songs')]
    #[ORM\JoinColumn(name: 'setlist_id', nullable: false, onDelete: 'CASCADE')]
    private Setlist $setlist;

    /** 0-based, playing order (AC-4.1). */
    #[ORM\Column(type: 'smallint')]
    private int $position;

    /** e.g. 'Encore 1', or null for the main set (AC-4.2). */
    #[ORM\Column(name: 'set_label', type: 'string', length: 40, nullable: true)]
    private ?string $setLabel = null;

    #[ORM\Column(type: 'string', length: 200)]
    private string $title;

    /** The original artist, when setlist.fm marks this song as a cover (AC-4.2). */
    #[ORM\Column(name: 'cover_of_name', type: 'string', length: 200, nullable: true)]
    private ?string $coverOfName = null;

    #[ORM\Column(name: 'cover_of_mbid', type: 'string', length: 64, nullable: true)]
    private ?string $coverOfMbid = null;

    /** Guest performer, when setlist.fm records one (AC-4.2). */
    #[ORM\Column(name: 'with_name', type: 'string', length: 200, nullable: true)]
    private ?string $withName = null;

    /** setlist.fm's free-text per-song note (AC-4.2). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $info = null;

    /** Played over the PA, not performed live — preserved, never filtered here (AC-4.3). */
    #[ORM\Column(name: 'is_tape', type: 'boolean')]
    private bool $isTape = false;

    public function __construct(
        Setlist $setlist,
        int $position,
        ?string $setLabel,
        string $title,
        ?string $coverOfName,
        ?string $coverOfMbid,
        ?string $withName,
        ?string $info,
        bool $isTape,
    ) {
        $this->setlist = $setlist;
        $this->position = $position;
        $this->setLabel = $setLabel;
        $this->title = $title;
        $this->coverOfName = $coverOfName;
        $this->coverOfMbid = $coverOfMbid;
        $this->withName = $withName;
        $this->info = $info;
        $this->isTape = $isTape;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSetlist(): Setlist
    {
        return $this->setlist;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getSetLabel(): ?string
    {
        return $this->setLabel;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCoverOfName(): ?string
    {
        return $this->coverOfName;
    }

    public function getCoverOfMbid(): ?string
    {
        return $this->coverOfMbid;
    }

    public function getWithName(): ?string
    {
        return $this->withName;
    }

    public function getInfo(): ?string
    {
        return $this->info;
    }

    public function isTape(): bool
    {
        return $this->isTape;
    }
}
