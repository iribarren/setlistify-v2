<?php

declare(strict_types=1);

namespace App\Service\Matching\Model;

/**
 * What `TrackMatcher::match()` can conclude about one song.
 *
 * Three of these are spec 12 §3's confidence banding (AUTO_ACCEPT / CHOICE / REJECT); `Skipped` is the
 * Tier-0 pre-filter verdict for an entry that is not a song at all (a tape, a drum solo) and is
 * therefore never searched. `region_restricted` is deliberately absent — availability is decided by
 * the provider at insert time, not by the matcher, and lives on `TrackOutcome` instead.
 */
enum MatchOutcome: string
{
    case Matched = 'matched';
    case MatchedLowConfidence = 'matched_low_confidence';
    case Skipped = 'skipped';
    case NotFound = 'not_found';
}
