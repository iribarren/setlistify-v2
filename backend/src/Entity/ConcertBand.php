<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConcertBandRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The lineup join row (US-1, D-25 background). `$billingOrder` is 0-based and contiguous within one
 * concert — index 0 is the headliner (AC-1.3) — and is the sole source of read-back order via
 * `Concert::$concertBands`'s `#[ORM\OrderBy]` (AC-1.4), never insertion order.
 *
 * A band may not appear twice in the same lineup (AC-1.6, `uniq_concert_bands_concert_band`), and
 * two rows in the same concert may not share a billing order (`uniq_concert_bands_concert_billing`).
 */
#[ORM\Entity(repositoryClass: ConcertBandRepository::class)]
#[ORM\Table(name: 'concert_bands')]
#[ORM\UniqueConstraint(name: 'uniq_concert_bands_concert_band', columns: ['concert_id', 'band_id'])]
#[ORM\UniqueConstraint(name: 'uniq_concert_bands_concert_billing', columns: ['concert_id', 'billing_order'])]
class ConcertBand
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Concert::class, inversedBy: 'concertBands')]
    #[ORM\JoinColumn(name: 'concert_id', nullable: false, onDelete: 'CASCADE')]
    private Concert $concert;

    #[ORM\ManyToOne(targetEntity: Band::class)]
    #[ORM\JoinColumn(name: 'band_id', nullable: false)]
    private Band $band;

    #[ORM\Column(name: 'billing_order', type: 'smallint')]
    private int $billingOrder;

    public function __construct(Concert $concert, Band $band, int $billingOrder)
    {
        $this->concert = $concert;
        $this->band = $band;
        $this->billingOrder = $billingOrder;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConcert(): Concert
    {
        return $this->concert;
    }

    public function getBand(): Band
    {
        return $this->band;
    }

    public function getBillingOrder(): int
    {
        return $this->billingOrder;
    }
}
