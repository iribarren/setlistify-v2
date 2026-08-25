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

    // Normal mode (docs/specs/2026-08-25-playlist-normal-mode.md)
    /** A song was auto-resolved from a live `UserTrackPreference` rather than by scoring (D-199). */
    case UsedYourPreviousChoice = 'USED_YOUR_PREVIOUS_CHOICE';
    /** The user explicitly declined every candidate for a song (AC-2.6) — a success path, not a miss. */
    case UserDeclined = 'USER_DECLINED';
    /** Staleness on resume (spec 13 §6) — the setlist was corrected on setlist.fm since selection. */
    case SetlistCorrectedSinceSelection = 'SETLIST_CORRECTED_SINCE_SELECTION';
    /** Staleness on resume — `algorithmVersion` was bumped while the job slept. */
    case RescoredAfterAlgorithmUpdate = 'RESCORED_AFTER_ALGORITHM_UPDATE';
    /** Staleness on resume — the chosen setlist was purged from cache; fell back to D-132's rule. */
    case SelectedSetlistUnavailable = 'SELECTED_SETLIST_UNAVAILABLE';
}
