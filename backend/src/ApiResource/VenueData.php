<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Validator\CountryCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `{ name, city, countryCode }` (D-26, AC-2.2). Used both as the nested `venue` shape on
 * `ConcertInput`/`ConcertPatchInput` (write) and on `ConcertOutput` (read) — the shape is identical
 * in both directions, so one class serves both (AC-2.7: omitted fields round-trip as `null`).
 */
final class VenueData
{
    public function __construct(
        #[Assert\Length(max: 200)]
        public ?string $name = null,
        #[Assert\Length(max: 200)]
        public ?string $city = null,
        #[CountryCode]
        public ?string $countryCode = null,
    ) {
    }
}
