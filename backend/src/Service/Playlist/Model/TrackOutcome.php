<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/** `PlaylistTrack::$outcome` — the five terminal outcomes plus the up-front skeleton state (spec 14 §4). */
enum TrackOutcome: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case MatchedLowConfidence = 'matched_low_confidence';
    case Skipped = 'skipped';
    case NotFound = 'not_found';
    case RegionRestricted = 'region_restricted';

    /** Whether this outcome counts toward the match-rate denominator (spec 14 §4). `skipped` does not. */
    public function countsInMatchRate(): bool
    {
        return !\in_array($this, [self::Pending, self::Skipped], true);
    }

    public function isHit(): bool
    {
        return \in_array($this, [self::Matched, self::MatchedLowConfidence], true);
    }
}
