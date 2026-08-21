<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * D-26: a Doctrine embeddable value object, not an entity and not three loose columns. Mapped
 * inline onto `concerts` (`venue_name`, `venue_city`, `venue_country_code`) and serialized as a
 * nested `venue` object (AC-2.2). All fields are free text and optional; `countryCode` is the one
 * validated field (ISO 3166-1 alpha-2, uppercased on write) because it is the join key a future
 * `Venue` table (prompt 24) would want.
 *
 * Value object: always replaced as a whole, never mutated field-by-field, so promoting it to an
 * entity later is an additive JSON change, not a breaking one.
 */
#[ORM\Embeddable]
final class Venue
{
    public function __construct(
        #[ORM\Column(type: 'string', length: 200, nullable: true)]
        private ?string $name = null,
        #[ORM\Column(type: 'string', length: 200, nullable: true)]
        private ?string $city = null,
        #[ORM\Column(name: 'country_code', type: 'string', length: 2, nullable: true)]
        private ?string $countryCode = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function isEmpty(): bool
    {
        return null === $this->name && null === $this->city && null === $this->countryCode;
    }
}
