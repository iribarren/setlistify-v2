<?php

declare(strict_types=1);

namespace App\Service\Playlist\Exception;

/**
 * F-02/F-03: no band on the lineup has a usable setlist — unknown to setlist.fm, ambiguous, or
 * every candidate is empty. Not a failure: `SetlistSelectionStage` catches this and lands the job
 * in `completed`/`no_source_material` (CLAUDE.md: generation degrades, it does not fail).
 */
final class NoSetlistsAvailableException extends \RuntimeException
{
}
