<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConcertRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The thing a Setlistify user actually owns (docs/architecture.md §10): a set of bands, on a date,
 * at a place, for a price (US-1, US-2).
 *
 * **Never a writable API resource** (D-29) — every write goes through `App\ApiResource\ConcertInput`
 * / `ConcertPatchInput` and a state processor; this class has no setters that map 1:1 onto request
 * input.
 *
 * `$date` + `$timezone` are the venue's local calendar date and IANA timezone (D-24) — never an
 * instant of their own. `$pastAfter` is the derived UTC boundary instant
 * `App\Service\Concert\ConcertScheduler` computes from them on every write (creation and update);
 * `status` (`upcoming`/`past`) is a single indexed comparison against it, never a stored flag.
 *
 * The lineup (`$concertBands`) is ordered strictly by `billingOrder` via `#[ORM\OrderBy]` (AC-1.4).
 * `cascade: ['persist', 'remove']` + `orphanRemoval: true` on the join collection is what makes
 * `ConcertBand` rows disappear with the concert (AC-6.2) while `Band` rows — never owned by this
 * cascade — survive (AC-6.3).
 *
 * There is no `note` column here any more (D-239,
 * docs/specs/2026-08-26-notes-and-reviews.md) — D-30's "one plain column" is superseded by
 * `App\Entity\ConcertReview`, which owns the content the `Version20260826140000` migration copied
 * out of it.
 */
#[ORM\Entity(repositoryClass: ConcertRepository::class)]
#[ORM\Table(name: 'concerts')]
#[ORM\Index(name: 'idx_concerts_owner_past_after', columns: ['owner_id', 'past_after'])]
#[ORM\Index(name: 'idx_concerts_owner_date', columns: ['owner_id', 'date'])]
class Concert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /** IANA identifier, e.g. `Europe/Madrid` (D-24). Never a fixed offset. */
    #[ORM\Column(type: 'string', length: 64)]
    private string $timezone;

    /**
     * Derived, never set directly by request input: `(date + 1 day) at 00:00 in $timezone`,
     * converted to UTC (D-24). `status` is `pastAfter <= now() ? past : upcoming`.
     */
    #[ORM\Column(name: 'past_after', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $pastAfter;

    #[ORM\Embedded(class: Venue::class, columnPrefix: 'venue_')]
    private Venue $venue;

    /** Integer minor units (D-28) — `4500` + `EUR` is €45.00. Null together with `$priceCurrency`. */
    #[ORM\Column(name: 'price_amount', type: 'integer', nullable: true)]
    private ?int $priceAmount = null;

    /** ISO 4217 alpha-3, uppercased (D-28). Null together with `$priceAmount`. */
    #[ORM\Column(name: 'price_currency', type: 'string', length: 3, nullable: true)]
    private ?string $priceCurrency = null;

    /** Local wall-clock time in `$timezone` — never an instant (AC-2.5). */
    #[ORM\Column(name: 'doors_time', type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $doorsTime = null;

    #[ORM\Column(name: 'start_time', type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ConcertBand> */
    #[ORM\OneToMany(mappedBy: 'concert', targetEntity: ConcertBand::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['billingOrder' => 'ASC'])]
    private Collection $concertBands;

    public function __construct(
        User $owner,
        \DateTimeImmutable $date,
        string $timezone,
        \DateTimeImmutable $pastAfter,
        \DateTimeImmutable $now,
    ) {
        $this->owner = $owner;
        $this->date = $date;
        $this->timezone = $timezone;
        $this->pastAfter = $pastAfter;
        $this->venue = Venue::empty();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->concertBands = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function getPastAfter(): \DateTimeImmutable
    {
        return $this->pastAfter;
    }

    /** AC-5.4: changing `date` or `timezone` re-derives `$pastAfter` in the same call. */
    public function reschedule(\DateTimeImmutable $date, string $timezone, \DateTimeImmutable $pastAfter, \DateTimeImmutable $now): void
    {
        $this->date = $date;
        $this->timezone = $timezone;
        $this->pastAfter = $pastAfter;
        $this->touch($now);
    }

    public function getVenue(): Venue
    {
        return $this->venue;
    }

    public function setVenue(Venue $venue, \DateTimeImmutable $now): void
    {
        $this->venue = $venue;
        $this->touch($now);
    }

    public function getPriceAmount(): ?int
    {
        return $this->priceAmount;
    }

    public function getPriceCurrency(): ?string
    {
        return $this->priceCurrency;
    }

    public function setPrice(?int $amount, ?string $currency, \DateTimeImmutable $now): void
    {
        $this->priceAmount = $amount;
        $this->priceCurrency = $currency;
        $this->touch($now);
    }

    public function getDoorsTime(): ?\DateTimeImmutable
    {
        return $this->doorsTime;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setTimes(?\DateTimeImmutable $doorsTime, ?\DateTimeImmutable $startTime, \DateTimeImmutable $now): void
    {
        $this->doorsTime = $doorsTime;
        $this->startTime = $startTime;
        $this->touch($now);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, ConcertBand> Ordered by `billingOrder` ascending (AC-1.4). */
    public function getConcertBands(): Collection
    {
        return $this->concertBands;
    }

    /** AC-5.2, AC-5.3: removes only the join rows (orphan removal); `Band` rows are untouched. */
    public function clearLineup(): void
    {
        $this->concertBands->clear();
    }

    public function addLineupEntry(Band $band, int $billingOrder): void
    {
        $this->concertBands->add(new ConcertBand($this, $band, $billingOrder));
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
