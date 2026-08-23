<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\PlaylistGenerationJob;

/**
 * Fast mode: a no-op. The CHOICE band (`matched_low_confidence`) is included and flagged, never
 * dropped (spec 12, resolved Q1) — `MatchingStage` already wrote that outcome. Normal mode's guard
 * (`mode === Normal && choiceBand !== []` → `awaiting_version_choice`) belongs to prompt 17 and is
 * deliberately absent here; `JobState::AwaitingVersionChoice` already exists in the state machine
 * for it to add (AC-6.1/AC-6.2).
 */
final readonly class ReviewStage
{
    public function run(PlaylistGenerationJob $job): void
    {
        // Fast mode never suspends here — see class docblock.
    }
}
