<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use App\Entity\Band;

/**
 * D-270/D-276 (docs/specs/2026-08-27-instant-setlist-refresh.md): thrown by
 * `BandIdentityResolver::resolveAmbiguousChoice()` when the band already carries an MBID — the pick
 * writes only into a vacancy, never overwrites (including a band resolved by another user seconds
 * earlier, AC-6.8/AC-6.14). Caught by `ResolveBandIdentityProcessor` and mapped to `422`
 * `band_already_resolved`.
 */
final class BandAlreadyResolvedException extends \RuntimeException
{
    public function __construct(Band $band)
    {
        parent::__construct(\sprintf('Band %d already holds a setlist.fm identity.', $band->getId() ?? 0));
    }
}
