<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * What `createPlaylist()` returns (AC-9.2, AC-11.4) — the provider's own playlist id (needed by
 * `addTracks()` and by `playlistEmbedUrl()`/`playlistDeepLink()`), its name as accepted by the
 * provider, and an external URL a human can open.
 */
final readonly class ProviderPlaylist
{
    public function __construct(
        public string $providerPlaylistId,
        public string $name,
        public string $externalUrl,
    ) {
    }
}
