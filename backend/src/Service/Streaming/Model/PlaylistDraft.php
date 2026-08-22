<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * What `createPlaylist()` is asked to create (AC-9.2, AC-11.4). Playlists created through the port
 * are private by default (D-87) — this value object carries no visibility flag because the adapter
 * never exposes one; a future public-playlist feature is an additive, deliberate scope change, not
 * a flag threaded through here.
 */
final readonly class PlaylistDraft
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }
}
