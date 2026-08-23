<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * "The two numbers that matter" (spec 13 §8, D-141/D-142): generation time and match quality, read
 * straight off `PlaylistGenerationJob`'s frozen metrics columns and `PlaylistTrack`'s per-song
 * outcomes — never recomputed from logs. Feeds the backoffice dashboard's "Playlist generation
 * (last 7 days)" panel. Read-only, uncached (consistent with D-53's other dashboard panels): an
 * operator should always see a live number, not a stale one.
 *
 * Match-rate formula (spec 12 §9, spec 13 §8):
 * `(matchedCount + lowConfidenceCount) / (songsTotal - skippedCount)`.
 */
final class PlaylistDashboardMetrics
{
    private const int WINDOW_DAYS = 7;

    /** p95 generation time above this many ms is worth investigating. */
    public const int P95_DURATION_THRESHOLD_MS = 90_000;

    /** A 7-day mean match rate below this is worth investigating. */
    public const float MATCH_RATE_THRESHOLD = 0.75;

    /** A blocked share above this fraction of jobs is worth investigating. */
    public const float BLOCKED_SHARE_THRESHOLD = 0.10;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *     jobsStarted: int,
     *     jobsCompleted: int,
     *     jobsBlocked: int,
     *     jobsFailed: int,
     *     p50DurationMs: float|null,
     *     p95DurationMs: float|null,
     *     meanMatchRate: float|null,
     *     notFoundRate: float|null,
     *     blockedReasonBreakdown: array<string, int>,
     *     topUnmatchedPairs: list<array{artist: string, title: string, count: int}>,
     *     investigate: array{p95Duration: bool, matchRate: bool, blockedShare: bool},
     * }
     */
    public function sevenDaySummary(): array
    {
        $since = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('-%d days', self::WINDOW_DAYS));

        $jobsStarted = $this->countJobs($since);
        $jobsCompleted = $this->countJobs($since, 'completed');
        $jobsBlocked = $this->countJobs($since, 'blocked');
        $jobsFailed = $this->countJobs($since, 'failed');

        $durations = $this->completedDurations($since);
        $p50 = self::percentile($durations, 0.50);
        $p95 = self::percentile($durations, 0.95);

        [$meanMatchRate, $notFoundRate] = $this->matchAndNotFoundRates($since);

        $blockedReasonBreakdown = $this->blockedReasonBreakdown($since);
        $topUnmatchedPairs = $this->topUnmatchedPairs($since);

        $blockedShare = $jobsStarted > 0 ? $jobsBlocked / $jobsStarted : 0.0;

        return [
            'jobsStarted' => $jobsStarted,
            'jobsCompleted' => $jobsCompleted,
            'jobsBlocked' => $jobsBlocked,
            'jobsFailed' => $jobsFailed,
            'p50DurationMs' => $p50,
            'p95DurationMs' => $p95,
            'meanMatchRate' => $meanMatchRate,
            'notFoundRate' => $notFoundRate,
            'blockedReasonBreakdown' => $blockedReasonBreakdown,
            'topUnmatchedPairs' => $topUnmatchedPairs,
            'investigate' => [
                'p95Duration' => null !== $p95 && $p95 > self::P95_DURATION_THRESHOLD_MS,
                'matchRate' => null !== $meanMatchRate && $meanMatchRate < self::MATCH_RATE_THRESHOLD,
                'blockedShare' => $blockedShare > self::BLOCKED_SHARE_THRESHOLD,
            ],
        ];
    }

    private function countJobs(\DateTimeImmutable $since, ?string $state = null): int
    {
        $sql = 'SELECT COUNT(*) FROM playlist_generation_jobs WHERE created_at >= :since';
        $params = ['since' => $since->format('Y-m-d H:i:s')];

        if (null !== $state) {
            $sql .= ' AND state = :state';
            $params['state'] = $state;
        }

        $value = $this->connection->fetchOne($sql, $params);

        return \is_numeric($value) ? (int) $value : 0;
    }

    /** @return list<int> */
    private function completedDurations(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT duration_ms FROM playlist_generation_jobs WHERE created_at >= :since AND state = 'completed' AND duration_ms IS NOT NULL ORDER BY duration_ms ASC",
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (mixed $v): int => self::toInt($v), $rows);
    }

    /** @param list<int> $sortedValues */
    private static function percentile(array $sortedValues, float $percentile): ?float
    {
        $count = \count($sortedValues);
        if (0 === $count) {
            return null;
        }

        $index = (int) ceil($percentile * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return (float) $sortedValues[$index];
    }

    /** @return array{0: float|null, 1: float|null} mean match rate, aggregate not-found rate */
    private function matchAndNotFoundRates(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT matched_count, low_confidence_count, not_found_count, songs_total, skipped_count
             FROM playlist_generation_jobs
             WHERE created_at >= :since AND state = 'completed'",
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $rateSum = 0.0;
        $rateCount = 0;
        $notFoundSum = 0;
        $denominatorSum = 0;

        foreach ($rows as $row) {
            $denominator = self::toInt($row['songs_total']) - self::toInt($row['skipped_count']);
            if ($denominator <= 0) {
                continue;
            }

            $matched = self::toInt($row['matched_count']) + self::toInt($row['low_confidence_count']);
            $rateSum += $matched / $denominator;
            ++$rateCount;

            $notFoundSum += self::toInt($row['not_found_count']);
            $denominatorSum += $denominator;
        }

        $meanMatchRate = $rateCount > 0 ? $rateSum / $rateCount : null;
        $notFoundRate = $denominatorSum > 0 ? $notFoundSum / $denominatorSum : null;

        return [$meanMatchRate, $notFoundRate];
    }

    /** @return array<string, int> keyed by `BlockedReason` backing value */
    private function blockedReasonBreakdown(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT blocked_reason, COUNT(*) AS cnt FROM playlist_generation_jobs
             WHERE created_at >= :since AND state = 'blocked' AND blocked_reason IS NOT NULL
             GROUP BY blocked_reason ORDER BY cnt DESC",
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[self::toStr($row['blocked_reason'])] = self::toInt($row['cnt']);
        }

        return $breakdown;
    }

    /**
     * `playlist_tracks` has no direct job reference, so the join runs
     * `playlist_tracks -> playlists -> playlist_generation_jobs` to scope by the job's `created_at`.
     *
     * @return list<array{artist: string, title: string, count: int}>
     */
    private function topUnmatchedPairs(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.name AS artist, pt.source_title AS title, COUNT(*) AS cnt
             FROM playlist_tracks pt
             INNER JOIN playlists p ON p.id = pt.playlist_id
             INNER JOIN playlist_generation_jobs pgj ON pgj.id = p.job_id
             INNER JOIN bands b ON b.id = pt.source_band_id
             WHERE pgj.created_at >= :since AND pt.outcome = :outcome
             GROUP BY b.name, pt.source_title
             ORDER BY cnt DESC, artist ASC, title ASC
             LIMIT 5',
            ['since' => $since->format('Y-m-d H:i:s'), 'outcome' => 'not_found'],
        );

        return array_map(static fn (array $row): array => [
            'artist' => self::toStr($row['artist']),
            'title' => self::toStr($row['title']),
            'count' => self::toInt($row['cnt']),
        ], $rows);
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
