<?php

declare(strict_types=1);

namespace App\Service\Playlist\Naming;

use App\Entity\Concert;
use App\Entity\ConcertBand;

/**
 * `<Headliner> — <Venue city>, <D Mon YYYY>` (spec 13 §11, D-140). Deterministic from the concert
 * alone, which is what makes F-14's "find the possible orphan in your account" instruction
 * actionable. Provider length limits are the adapter's business, never this class's (D-73's
 * spirit).
 */
final readonly class PlaylistNamer
{
    public function name(Concert $concert): string
    {
        $headliner = self::headliner($concert);
        $date = $concert->getDate()->format('j M Y');
        $city = $concert->getVenue()->getCity();

        if (null !== $city && '' !== $city) {
            return \sprintf('%s — %s, %s', $headliner, $city, $date);
        }

        return \sprintf('%s — %s', $headliner, $date);
    }

    public function description(Concert $concert, int $jobId, int $matchedTotal, int $songsTotal): string
    {
        $lineup = implode(', ', array_map(
            static fn (ConcertBand $cb): string => $cb->getBand()->getName(),
            $concert->getConcertBands()->toArray(),
        ));
        $venue = $concert->getVenue()->getName();
        $date = $concert->getDate()->format('j M Y');

        $description = \sprintf(
            'The setlist from %s%s, %s. Built by Setlistify.',
            $lineup,
            null !== $venue && '' !== $venue ? \sprintf(', %s', $venue) : '',
            $date,
        );

        if ($matchedTotal < $songsTotal) {
            $description .= \sprintf(' (%d of %d songs matched)', $matchedTotal, $songsTotal);
        }

        return \sprintf('%s Setlistify job #%d.', $description, $jobId);
    }

    private static function headliner(Concert $concert): string
    {
        foreach ($concert->getConcertBands() as $concertBand) {
            // Index 0 = headliner (D-25); Concert::$concertBands is ordered by billingOrder ASC.
            return $concertBand->getBand()->getName();
        }

        return 'Concert';
    }
}
