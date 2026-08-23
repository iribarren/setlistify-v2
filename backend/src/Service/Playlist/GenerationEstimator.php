<?php

declare(strict_types=1);

namespace App\Service\Playlist;

/**
 * `estimatedSecondsRemaining` from a rolling per-song p50 (spec 13 §7). With no history yet, falls
 * back to a conservative flat estimate rather than reporting nothing — the polling contract always
 * carries a number while a job is active.
 */
final readonly class GenerationEstimator
{
    private const float DEFAULT_SECONDS_PER_SONG = 1.5;

    /** @param list<float> $recentPerSongDurationsSeconds a rolling window of recently observed per-song times */
    public function estimateSecondsRemaining(int $songsProcessed, int $songsTotal, array $recentPerSongDurationsSeconds): int
    {
        $remaining = $songsTotal - $songsProcessed;
        if ($remaining <= 0) {
            return 0;
        }

        $perSong = [] === $recentPerSongDurationsSeconds
            ? self::DEFAULT_SECONDS_PER_SONG
            : self::median($recentPerSongDurationsSeconds);

        return (int) ceil($remaining * $perSong);
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = \count($values);
        $mid = intdiv($count, 2);

        if (0 === $count % 2) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }
}
