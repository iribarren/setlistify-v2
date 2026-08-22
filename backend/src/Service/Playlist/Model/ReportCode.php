<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/**
 * Codes and parameters, never rendered English (D-141) — prompt 15 writes the sentences, prompt 16
 * renders them. Per-song codes live on `PlaylistTrack::$reasonCode`; job-level codes live in
 * `Playlist::$reportSummary`.
 */
enum ReportCode: string
{
    // Per-song
    case CoverOf = 'COVER_OF';
    case LiveVersionOnly = 'LIVE_VERSION_ONLY';
    case LowConfidenceMatch = 'LOW_CONFIDENCE_MATCH';
    case TapeNotPerformed = 'TAPE_NOT_PERFORMED';
    case PerformanceArtifact = 'PERFORMANCE_ARTIFACT';
    case TrackNotInCatalog = 'TRACK_NOT_IN_CATALOG';
    case TrackVanished = 'TRACK_VANISHED';
    case NotAvailableInRegion = 'NOT_AVAILABLE_IN_REGION';

    // Job-level
    case NoSetlistForBand = 'NO_SETLIST_FOR_BAND';
    case SetlistMayBeStale = 'SETLIST_MAY_BE_STALE';
    case SelectedFrom = 'SELECTED_FROM';
    case BandsOmittedForLength = 'BANDS_OMITTED_FOR_LENGTH';
    case SetlistTruncated = 'SETLIST_TRUNCATED';
    case ResumedMidInsertion = 'RESUMED_MID_INSERTION';
    case FallbackLongestSetlist = 'FALLBACK_LONGEST_SETLIST';
}
