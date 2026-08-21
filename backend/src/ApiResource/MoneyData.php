<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Validator\CurrencyCode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `{ amount, currency }` — integer minor units + ISO 4217 alpha-3 (D-28). `amount` without
 * `currency`, or vice versa, is a 422 (AC-2.3); enforced by `App\Validator\ValidConcertInputValidator`
 * since it is a cross-field check within this object, not a per-property one.
 */
final class MoneyData
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $amount = null,
        #[CurrencyCode]
        public ?string $currency = null,
    ) {
    }
}
