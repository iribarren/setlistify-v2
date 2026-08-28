<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The instant setlist refresh's async unit of work (docs/specs/2026-08-27-instant-setlist-refresh.md,
 * D-256, AC-3.2). One message serves both entry points: the plain trigger (US-1) and the pick's
 * completion (US-6, AC-6.12) — `$identityAlreadySettled` tells the handler to skip the search and go
 * straight to the index page.
 */
final readonly class RefreshBandSetlistsMessage
{
    public function __construct(
        public int $bandId,
        public bool $identityAlreadySettled,
    ) {
    }
}
