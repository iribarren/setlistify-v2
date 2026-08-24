<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Service\Playlist\Model\NoSetlistCause;
use App\Service\Playlist\Model\ReportCode;

/**
 * D-184's fold: turns the `NO_SETLIST_FOR_BAND` entries in a `Playlist::$reportSummary` into the one
 * `noSetlistCause` both output DTOs carry. Shared by `PlaylistOutputMapper` and
 * `PlaylistGenerationJobOutputMapper` so the rule lives in exactly one place.
 */
final class NoSetlistCauseFolder
{
    /**
     * @param array<int, array{code: string, params: array<string, mixed>}> $reportSummary
     */
    public function fold(array $reportSummary): ?NoSetlistCause
    {
        $lastCause = null;

        foreach ($reportSummary as $entry) {
            if (ReportCode::NoSetlistForBand->value !== $entry['code']) {
                continue;
            }

            $causeValue = $entry['params']['cause'] ?? null;
            if (!\is_string($causeValue)) {
                continue;
            }

            $cause = NoSetlistCause::from($causeValue);

            // Rule 1: any band known-but-empty wins outright — at least one band on the bill IS
            // known on setlist.fm, so the "known on setlist.fm" framing is truthful.
            if (NoSetlistCause::NoSetlistForShow === $cause) {
                return $cause;
            }

            $lastCause = $cause;
        }

        // Rule 2: no `no_setlist_for_show` entry — the LAST NO_SETLIST_FOR_BAND entry is the
        // headliner's (SetlistSelectionStage iterates support-acts-first, headliner-last).
        // Rule 3: no entries at all -> null, the client's existing default.
        return $lastCause;
    }
}
