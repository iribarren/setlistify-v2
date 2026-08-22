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
 * `$setlistfmMbid` is the band's stable setlist.fm identity (D-56, prompt 09) — every setlist.fm
 * call for a resolved band uses it, never the typed name. `$setlistfmName` is setlist.fm's own
 * canonical name, stored alongside without ever overwriting `$name` (AC-1.3, honouring AC-8.4 of
 * prompt 05). `$setlistfmResolutionState` records where this band stands in identity resolution
 * (US-1, US-2, US-5); `$setlistfmCheckedAt`/`$setlistfmResolvedAt` record when a search last ran
 * and when an MBID was last chosen, respectively. All four are written exclusively by
 * `App\Service\Setlist\BandIdentityResolver` (or an audited backoffice correction, AC-11.5).
 */
#[ORM\Entity(repositoryClass: BandRepository::class)]
#[ORM\Table(name: 'bands')]
#[ORM\UniqueConstraint(name: 'uniq_bands_normalized_name', columns: ['normalized_name'])]
#[ORM\Index(name: 'idx_bands_setlistfm_mbid', columns: ['setlistfm_mbid'])]
class Band
{
    public const string RESOLUTION_UNRESOLVED = 'unresolved';
    public const string RESOLUTION_RESOLVED = 'resolved';
    public const string RESOLUTION_AMBIGUOUS = 'ambiguous';
    public const string RESOLUTION_NO_PRESENCE = 'no_presence';

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

    #[ORM\Column(name: 'setlistfm_name', type: 'string', length: 200, nullable: true)]
    private ?string $setlistfmName = null;

    /** One of the `RESOLUTION_*` constants (US-1, US-2, US-5, D-56). */
    #[ORM\Column(name: 'setlistfm_resolution_state', type: 'string', length: 20)]
    private string $setlistfmResolutionState = self::RESOLUTION_UNRESOLVED;

    #[ORM\Column(name: 'setlistfm_checked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $setlistfmCheckedAt = null;

    #[ORM\Column(name: 'setlistfm_resolved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $setlistfmResolvedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * NOT a mapped column — transient, admin-list-only aggregate (AC-6.5), same `AS HIDDEN
     * concertCount` pattern as {@see User::$concertCount}.
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

    public function getSetlistfmName(): ?string
    {
        return $this->setlistfmName;
    }

    public function getSetlistfmResolutionState(): string
    {
        return $this->setlistfmResolutionState;
    }

    public function getSetlistfmCheckedAt(): ?\DateTimeImmutable
    {
        return $this->setlistfmCheckedAt;
    }

    public function getSetlistfmResolvedAt(): ?\DateTimeImmutable
    {
        return $this->setlistfmResolvedAt;
    }

    /** Resolved to exactly one MBID (AC-1.3, AC-2.3, AC-2.4, AC-11.5's correction path). */
    public function resolveTo(string $mbid, ?string $setlistfmName, \DateTimeImmutable $now): void
    {
        $this->setlistfmMbid = $mbid;
        $this->setlistfmName = $setlistfmName;
        $this->setlistfmResolutionState = self::RESOLUTION_RESOLVED;
        $this->setlistfmCheckedAt = $now;
        $this->setlistfmResolvedAt = $now;
        $this->updatedAt = $now;
    }

    /** AC-2.1: more than one plausible candidate — resolution deferred to a user/operator choice. */
    public function markAmbiguous(\DateTimeImmutable $now): void
    {
        $this->setlistfmResolutionState = self::RESOLUTION_AMBIGUOUS;
        $this->setlistfmCheckedAt = $now;
        $this->updatedAt = $now;
    }

    /** AC-5.1: a search returned zero candidates. */
    public function markNoPresence(\DateTimeImmutable $now): void
    {
        $this->setlistfmResolutionState = self::RESOLUTION_NO_PRESENCE;
        $this->setlistfmCheckedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * AC-2.6: an operator correction clears any prior resolution back to a clean slate — used
     * together with clearing the band's cached setlist associations (AC-11.5).
     */
    public function resetResolution(\DateTimeImmutable $now): void
    {
        $this->setlistfmMbid = null;
        $this->setlistfmName = null;
        $this->setlistfmResolutionState = self::RESOLUTION_UNRESOLVED;
        $this->setlistfmCheckedAt = null;
        $this->setlistfmResolvedAt = null;
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

    /** See {@see self::$concertCount} — only meaningful after the admin list query. */
    public function getConcertCount(): int
    {
        return $this->concertCount;
    }
}
