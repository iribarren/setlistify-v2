<?php

declare(strict_types=1);

namespace App\Service\Playlist\Model;

/**
 * The eleven states of `PlaylistGenerationJob::$state` (spec 13 §1). `JobStateMachine` is the only
 * class permitted to assign this column (D-159) — every other write is a bug caught by
 * `JobStateMachineIsOnlyStateWriterTest`.
 *
 * Fast mode (this feature) never reaches `AwaitingSetlistChoice` or `AwaitingVersionChoice` — they
 * exist so prompt 17 (Normal mode) adds two guards to the same pipeline instead of a second one
 * (D-125, AC-6.1).
 */
enum JobState: string
{
    case Queued = 'queued';
    case ResolvingSetlist = 'resolving_setlist';
    case AwaitingSetlistChoice = 'awaiting_setlist_choice';
    case Matching = 'matching';
    case AwaitingVersionChoice = 'awaiting_version_choice';
    case Building = 'building';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Expired, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Queued, self::ResolvingSetlist, self::Matching, self::Building => true,
            default => false,
        };
    }
}
