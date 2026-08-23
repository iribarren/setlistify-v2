<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

use App\Service\Streaming\Model\SongQuery;

/**
 * Builds the provider's search string from a `SongQuery` (D-147, part 3). Extracted out of
 * `SpotifyProvider::searchTrack()` so query construction — including a future `market` parameter —
 * has exactly one home inside this adapter, rather than being inlined at the call site.
 */
final class SpotifyQueryBuilder
{
    /** @return array<string, scalar> the `/v1/search` query parameters */
    public function build(SongQuery $query, int $limit = 20, ?string $market = null): array
    {
        $params = [
            'q' => \sprintf('track:%s artist:%s', $query->songTitle, $query->bandName),
            'type' => 'track',
            'limit' => $limit,
        ];

        if (null !== $market) {
            $params['market'] = $market;
        }

        return $params;
    }
}
