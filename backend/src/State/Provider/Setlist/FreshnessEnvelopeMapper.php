<?php

declare(strict_types=1);

namespace App\State\Provider\Setlist;

use App\ApiResource\Setlist\FreshnessEnvelope;
use App\Service\Setlist\CachedFetch;

/** Shared `CachedFetch` → `FreshnessEnvelope` mapping (D-63) for every setlist.fm-backed provider. */
final class FreshnessEnvelopeMapper
{
    public static function from(CachedFetch $fetch): FreshnessEnvelope
    {
        if ($fetch->stale || null !== $fetch->reason) {
            \assert(null !== $fetch->reason);

            return FreshnessEnvelope::degraded($fetch->source, $fetch->fetchedAt, $fetch->reason, $fetch->budgetResetAt);
        }

        return FreshnessEnvelope::fresh($fetch->source, $fetch->fetchedAt);
    }
}
