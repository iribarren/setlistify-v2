<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/** `PlaylistGenerationJob::$currentStage` — one of §3's seven stages, or null before the pipeline starts. */
enum PipelineStage: string
{
    case Preflight = 'preflight';
    case SetlistSelection = 'setlist_selection';
    case Matching = 'matching';
    case Review = 'review';
    case Creation = 'creation';
    case Insertion = 'insertion';
    case Report = 'report';
}
