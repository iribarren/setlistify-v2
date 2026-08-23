<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Entity\Setlist;
use App\Service\Playlist\Model\SelectionReason;

/**
 * "Most recent substantial" (spec 13 §9, D-132). Over the most recent `SELECTION_WINDOW` non-empty
 * setlists, picks the first one that clears a median-relative threshold within the recency limit —
 * so a band's most recent entry being a three-song festival slot does not produce a four-track
 * playlist for a twenty-two-song show.
 */
final readonly class SubstantialSetlistSelector
{
    private const int SELECTION_WINDOW = 20;
    private const int MEDIAN_SAMPLE = 10;
    private const int SUBSTANTIAL_FLOOR = 8;
    private const float SUBSTANTIAL_RATIO = 0.60;
    private const int RECENCY_LIMIT_MONTHS = 24;

    /** @param list<Setlist> $candidatesNewestFirst already ordered by eventDate DESC */
    public function select(array $candidatesNewestFirst, \DateTimeImmutable $now): ?SelectionResult
    {
        $nonEmpty = array_values(array_filter(
            $candidatesNewestFirst,
            static fn (Setlist $s): bool => !$s->isEmpty() && $s->getSongCount() > 0,
        ));

        if ([] === $nonEmpty) {
            return null;
        }

        $window = \array_slice($nonEmpty, 0, self::SELECTION_WINDOW);

        if (1 === \count($window)) {
            return new SelectionResult($window[0], SelectionReason::OnlyOneAvailable);
        }

        $sample = \array_slice($window, 0, self::MEDIAN_SAMPLE);
        $median = self::median(array_map(static fn (Setlist $s): int => $s->getSongCount(), $sample));
        $threshold = max(self::SUBSTANTIAL_FLOOR, (int) ceil(self::SUBSTANTIAL_RATIO * $median));
        $recencyLimit = $now->modify(\sprintf('-%d months', self::RECENCY_LIMIT_MONTHS));

        foreach ($window as $setlist) {
            if ($setlist->getSongCount() >= $threshold && $setlist->getEventDate() >= $recencyLimit) {
                return new SelectionResult($setlist, SelectionReason::MostRecentSubstantial);
            }
        }

        // Fallback: the longest setlist in the window.
        $longest = $window[0];
        foreach ($window as $setlist) {
            if ($setlist->getSongCount() > $longest->getSongCount()) {
                $longest = $setlist;
            }
        }

        return new SelectionResult($longest, SelectionReason::FallbackLongest);
    }

    /** @param list<int> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = \count($values);
        $mid = intdiv($count, 2);

        if (0 === $count % 2) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return (float) $values[$mid];
    }
}
