<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

use App\Entity\Band;

/**
 * Why a band produced no selectable setlist, at the moment `SetlistSelectionStage::run()` gives up
 * on it (D-183). Derived from `Band::$setlistfmResolutionState` — read at the `null === $result`
 * branch, never from a fresh setlist.fm call.
 */
enum NoSetlistCause: string
{
    case BandUnknown = 'band_unknown';
    case BandAmbiguous = 'band_ambiguous';
    case NoSetlistForShow = 'no_setlist_for_show';
    case IdentityUnavailable = 'identity_unavailable';

    /**
     * Maps a `Band::RESOLUTION_*` constant to its cause. Throws on an unrecognised state rather than
     * silently defaulting (T-1) — a resolution state this doesn't know about is a bug to surface, not
     * a case to guess at.
     */
    public static function forResolutionState(string $resolutionState): self
    {
        return match ($resolutionState) {
            Band::RESOLUTION_NO_PRESENCE => self::BandUnknown,
            Band::RESOLUTION_AMBIGUOUS => self::BandAmbiguous,
            Band::RESOLUTION_RESOLVED => self::NoSetlistForShow,
            Band::RESOLUTION_UNRESOLVED => self::IdentityUnavailable,
            default => throw new \InvalidArgumentException(\sprintf('Unknown Band resolution state "%s".', $resolutionState)),
        };
    }
}
