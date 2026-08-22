<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

/**
 * AC-1.3, D-88: the entire requested scope list, declared in exactly one place. Identity
 * (`user-read-private`) and private playlist writing (`playlist-modify-private`) — the minimum this
 * feature needs. No scope is requested "for later"; `playlist-modify-public` is deliberately absent
 * (D-87 — playlists created by the port are private by default).
 */
final class SpotifyScopes
{
    public const array REQUESTED = [
        'user-read-private',
        'playlist-modify-private',
    ];

    public static function asSpaceSeparatedString(): string
    {
        return implode(' ', self::REQUESTED);
    }
}
