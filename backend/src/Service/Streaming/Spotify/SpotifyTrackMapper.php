<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

use App\Service\Streaming\Model\AlbumType;
use App\Service\Streaming\Model\ArtistAuthority;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Model\TrackCandidate;

/**
 * Maps a provider search response into `TrackCandidate[]` (AC-11.1). `isLive`/`isCover` are read
 * from the track/album name only when the provider's metadata makes it available — no dedicated
 * "live version" field exists in the search response, so this remains a naive heuristic (AC-11.3:
 * `false`, never `null`, when nothing indicates otherwise).
 *
 * **D-83's provisional string-distance scorer is gone outright (D-147).** This class's only
 * remaining job relative to matching is signal extraction: populating `TrackCandidate`'s generic,
 * provider-agnostic fields (D-119) so `App\Service\Matching\MatchConfidence` can score the
 * candidate itself. `confidence` is still set, but purely as a result-rank-derived ordering hint
 * that scorer never reads.
 */
final class SpotifyTrackMapper
{
    /**
     * @param array<string, mixed> $searchResponse the decoded `/v1/search` JSON body
     *
     * @return list<TrackCandidate> in the provider's own result order (AC-11.1) — `providerRank`
     *                              (0-based) IS that order, so no re-sort happens here
     */
    public function mapSearchResponse(array $searchResponse, SongQuery $query): array
    {
        $tracksSection = $searchResponse['tracks'] ?? null;
        if (!\is_array($tracksSection)) {
            return [];
        }

        $items = $tracksSection['items'] ?? null;
        if (!\is_array($items)) {
            return [];
        }

        $items = array_values($items);
        $count = \count($items);

        $candidates = [];
        foreach ($items as $rank => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $candidate = $this->mapItem($item, $rank, $count);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @param array<array-key, mixed> $item */
    private function mapItem(array $item, int $rank, int $count): ?TrackCandidate
    {
        $id = $item['id'] ?? null;
        $title = $item['name'] ?? null;
        if (!\is_string($id) || !\is_string($title)) {
            return null;
        }

        $artists = $item['artists'] ?? [];
        $artistName = '';
        if (\is_array($artists) && \is_array($artists[0] ?? null) && \is_string($artists[0]['name'] ?? null)) {
            $artistName = $artists[0]['name'];
        }

        $album = $item['album'] ?? null;
        $albumName = \is_array($album) && \is_string($album['name'] ?? null) ? $album['name'] : null;
        $albumType = \is_array($album) ? $this->mapAlbumType($album) : null;

        $durationMs = \is_int($item['duration_ms'] ?? null) ? $item['duration_ms'] : 0;

        $popularityRaw = $item['popularity'] ?? null;
        $popularity = \is_int($popularityRaw) ? max(0.0, min(1.0, $popularityRaw / 100)) : null;

        $externalIds = $item['external_ids'] ?? null;
        $isrc = \is_array($externalIds) && \is_string($externalIds['isrc'] ?? null) ? $externalIds['isrc'] : null;

        $haystack = strtolower($title.' '.($albumName ?? ''));

        return new TrackCandidate(
            providerTrackId: $id,
            title: $title,
            artist: $artistName,
            album: $albumName,
            durationMs: $durationMs,
            isLive: str_contains($haystack, 'live'),
            isCover: str_contains($haystack, 'cover'),
            // An ordering hint only — App\Service\Matching\MatchConfidence never reads this (D-147).
            confidence: $count > 0 ? round(1 - ($rank / $count), 4) : 0.0,
            // Spotify's search response carries no artist-verification signal, so this provider
            // always leaves the authority signal absent rather than guessing at one.
            artistAuthority: ArtistAuthority::Unknown,
            albumType: $albumType,
            popularity: $popularity,
            isrc: $isrc,
            providerRank: $rank,
        );
    }

    /**
     * Spotify's `album_type` is only ever `album`/`single`/`compilation` — narrower than spec 12
     * §3's five-way `AlbumType`. `Ep`/`LiveAlbum` are adapter-local heuristics on top of that, kept
     * here rather than in matching because they are entirely provider-shaped guesses: a `single`
     * with 4–6 tracks is conventionally an EP, and an `album` whose name says "live" is a live
     * release. Neither heuristic firing leaves the signal at Spotify's own `album`/`single`/
     * `compilation` mapping, never null — the provider did answer, just less precisely.
     *
     * @param array<array-key, mixed> $album
     */
    private function mapAlbumType(array $album): ?AlbumType
    {
        $rawType = $album['album_type'] ?? null;
        if (!\is_string($rawType)) {
            return null;
        }

        $albumName = \is_string($album['name'] ?? null) ? strtolower($album['name']) : '';
        $totalTracks = \is_int($album['total_tracks'] ?? null) ? $album['total_tracks'] : null;

        return match ($rawType) {
            'album' => str_contains($albumName, 'live') ? AlbumType::LiveAlbum : AlbumType::Album,
            'single' => null !== $totalTracks && $totalTracks >= 4 && $totalTracks <= 6
                ? AlbumType::Ep
                : AlbumType::Single,
            'compilation' => AlbumType::Compilation,
            default => null,
        };
    }
}
