<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConcertReviewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user's write-up of one concert (docs/specs/2026-08-26-notes-and-reviews.md, D-227). Its own
 * entity rather than four more columns on `Concert` — see the spec's rationale — because prompt 21
 * needs something a `ShareLink` can include or exclude by *row*, not by remembering which columns
 * to omit from a serialization.
 *
 * `UNIQUE (owner_id, concert_id)` (`uniq_concert_reviews_owner_concert`) states the invariant this
 * entity actually depends on: `$owner` always equals `$concert->getOwner()` (D-227, AC-3.5), which
 * `App\State\Processor\ConcertReviewPutProcessor` asserts on every write rather than assuming.
 *
 * The highlight is deliberately both a nullable `Song` FK (`ON DELETE SET NULL`, D-232) and an
 * always-populated snapshot (`$highlightTitle`) — the same shape as `PlaylistTrack::$sourceSong`/
 * `$sourceTitle` (`backend/src/Entity/PlaylistTrack.php`). `$highlightTitle` is the ONLY thing ever
 * rendered (AC-5.4); the FK exists only so a future aggregation feature has joinable identity.
 *
 * `$sourceNoteMigratedAt` is provenance, never exposed through the API (D-240): non-null iff this
 * row was created by the `Concert.note` migration rather than written through the review endpoint.
 */
#[ORM\Entity(repositoryClass: ConcertReviewRepository::class)]
#[ORM\Table(name: 'concert_reviews')]
#[ORM\UniqueConstraint(name: 'uniq_concert_reviews_owner_concert', columns: ['owner_id', 'concert_id'])]
#[ORM\Index(name: 'idx_concert_reviews_concert', columns: ['concert_id'])]
#[ORM\Index(name: 'idx_concert_reviews_highlight_song', columns: ['highlight_song_id'])]
class ConcertReview
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

    /** 1-5 inclusive, DB `CHECK` plus `Assert\Range` (D-230). Nullable — a review may be notes-only (D-231). */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $rating = null;

    /** Plain text, no rendering contract (D-237), ≤ 4000 graphemes (D-236). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** A purged/replaced setlist song must not blank the highlight — `ON DELETE SET NULL` (D-232). */
    #[ORM\ManyToOne(targetEntity: Song::class)]
    #[ORM\JoinColumn(name: 'highlight_song_id', nullable: true, onDelete: 'SET NULL')]
    private ?Song $highlightSong = null;

    /** The snapshot. The ONLY value ever rendered (AC-5.4). */
    #[ORM\Column(name: 'highlight_title', type: 'string', length: 200, nullable: true)]
    private ?string $highlightTitle = null;

    /** Provenance only (D-240): non-null iff created by the `Concert.note` migration. Never exposed via the API. */
    #[ORM\Column(name: 'source_note_migrated_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $sourceNoteMigratedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, Concert $concert, \DateTimeImmutable $now)
    {
        $this->owner = $owner;
        $this->concert = $concert;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getHighlightSong(): ?Song
    {
        return $this->highlightSong;
    }

    public function getHighlightTitle(): ?string
    {
        return $this->highlightTitle;
    }

    public function getSourceNoteMigratedAt(): ?\DateTimeImmutable
    {
        return $this->sourceNoteMigratedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** AC-2.6: `updatedAt` changes on every successful write; `createdAt` never does. */
    public function apply(?int $rating, ?string $notes, ?Song $highlightSong, ?string $highlightTitle, \DateTimeImmutable $now): void
    {
        $this->rating = $rating;
        $this->notes = $notes;
        $this->highlightSong = $highlightSong;
        $this->highlightTitle = $highlightTitle;
        $this->updatedAt = $now;
    }

    /**
     * Migration-only constructor path (AC-8.1): a migrated row is always notes-only (`rating` and
     * the highlight are always `NULL`), and its timestamps come from the source `Concert` row, not
     * "now" — see `Version20260826140000`.
     */
    public static function fromMigratedNote(User $owner, Concert $concert, string $notes, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt, \DateTimeImmutable $migratedAt): self
    {
        $review = new self($owner, $concert, $createdAt);
        $review->notes = $notes;
        $review->updatedAt = $updatedAt;
        $review->sourceNoteMigratedAt = $migratedAt;

        return $review;
    }
}
