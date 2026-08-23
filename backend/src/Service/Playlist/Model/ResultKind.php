<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/** `PlaylistGenerationJob::$resultKind`, frozen at `completed` (spec 14 §4). */
enum ResultKind: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case NoSourceMaterial = 'no_source_material';
    case NoTracksMatched = 'no_tracks_matched';
}
