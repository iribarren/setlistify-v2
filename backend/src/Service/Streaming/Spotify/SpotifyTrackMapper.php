<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Model\TrackCandidate;

/**
 * Maps a provider search response into `TrackCandidate[]` (AC-11.1). `isLive`/`isCover` are read
 * from the track/album name only when the provider's metadata makes it available — no dedicated
 * "live version" field exists in the search response, so this is itself a naive heuristic, not just
 * the confidence score (AC-11.3: `false`, never `null`, when nothing indicates otherwise).
 */
final class SpotifyTrackMapper
{
    /**
     * @param array<string, mixed> $searchResponse the decoded `/v1/search` JSON body
     *
     * @return list<TrackCandidate> ordered by descending confidence (AC-11.1)
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

        $candidates = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $candidate = $this->mapItem($item, $query);
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, static fn (TrackCandidate $a, TrackCandidate $b): int => $b->confidence <=> $a->confidence);

        return $candidates;
    }

    /** @param array<array-key, mixed> $item */
    private function mapItem(array $item, SongQuery $query): ?TrackCandidate
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

        $durationMs = \is_int($item['duration_ms'] ?? null) ? $item['duration_ms'] : 0;

        $haystack = strtolower($title.' '.($albumName ?? ''));

        return new TrackCandidate(
            providerTrackId: $id,
            title: $title,
            artist: $artistName,
            album: $albumName,
            durationMs: $durationMs,
            isLive: str_contains($haystack, 'live'),
            isCover: str_contains($haystack, 'cover'),
            confidence: $this->naiveConfidence($query, $title, $artistName),
        );
    }

    /**
     * **Deliberately provisional (D-83, AC-11.2)** — prompt 12 replaces this method's body with a
     * designed ranking. Normalized string similarity of title and artist against the query, 0.0–1.0,
     * weighted 70/30 toward the title. No downstream consumer trusts this yet (prompt 14 lands after
     * prompt 12).
     */
    private function naiveConfidence(SongQuery $query, string $candidateTitle, string $candidateArtist): float
    {
        $titleScore = $this->similarity($query->songTitle, $candidateTitle);
        $artistScore = $this->similarity($query->bandName, $candidateArtist);

        return round(($titleScore * 0.7) + ($artistScore * 0.3), 4);
    }

    private function similarity(string $a, string $b): float
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ('' === $a && '' === $b) {
            return 1.0;
        }
        if ('' === $a || '' === $b) {
            return 0.0;
        }

        $maxLength = max(\strlen($a), \strlen($b));
        $distance = levenshtein($a, $b);

        return max(0.0, 1.0 - ($distance / $maxLength));
    }
}
