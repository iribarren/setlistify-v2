<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * A candidate's release type, per the provider's own catalog metadata (D-119, spec 12 §3 signal 5).
 * Absent (`TrackCandidate::$albumType === null`) when the provider does not report one — the signal
 * then drops out of `MatchConfidence`'s renormalization rather than scoring as zero.
 */
enum AlbumType: string
{
    case Album = 'album';
    case Single = 'single';
    case Ep = 'ep';
    case Compilation = 'compilation';
    case LiveAlbum = 'live_album';
}
