<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `{ band: { id, name }, billingOrder }` — never a bare string (AC-1.7), so prompt 09 can add
 * `setlistfmMbid` to the band object without a breaking change.
 */
final readonly class LineupEntryOutput
{
    public function __construct(
        public BandOutput $band,
        public int $billingOrder,
    ) {
    }
}
