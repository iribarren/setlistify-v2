<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BandRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A band, shared across every user who has recorded a concert with it (US-8, D-25). There is no
 * public `POST /api/bands` — a `Band` row only ever comes into existence as a side effect of
 * `App\Service\Concert\BandResolver::resolve()`, called while creating or updating a `Concert`.
 *
 * `$name` is whatever the *first* creator typed (AC-8.4); `$normalizedName` is the deduplication
 * key (AC-8.2, unique index) computed by `BandResolver::normalize()` — a service method, not a
 * database function, so prompt 09 can replace the rule and re-derive the column without touching
 * any query.
 *
 * `$setlistfmMbid` is unused in this feature (out of scope, prompt 09) — the nullable column exists
 * now so that prompt is a migration-free change.
 */
#[ORM\Entity(repositoryClass: BandRepository::class)]
#[ORM\Table(name: 'bands')]
#[ORM\UniqueConstraint(name: 'uniq_bands_normalized_name', columns: ['normalized_name'])]
#[ORM\Index(name: 'idx_bands_setlistfm_mbid', columns: ['setlistfm_mbid'])]
class Band
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'normalized_name', type: 'string', length: 120)]
    private string $normalizedName;

    #[ORM\Column(name: 'setlistfm_mbid', type: 'string', length: 64, nullable: true)]
    private ?string $setlistfmMbid = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * NOT a mapped column — transient, admin-list-only aggregate (AC-6.5), same `AS HIDDEN
     * concertCount` pattern as {@see \App\Entity\User::$concertCount}.
     */
    private int $concertCount = 0;

    public function __construct(string $name, string $normalizedName, \DateTimeImmutable $now)
    {
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getSetlistfmMbid(): ?string
    {
        return $this->setlistfmMbid;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** See {@see self::$concertCount} — only meaningful after the admin list query. */
    public function getConcertCount(): int
    {
        return $this->concertCount;
    }
}
