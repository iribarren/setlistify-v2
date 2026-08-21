<?php

declare(strict_types=1);

namespace App\Service\Concert;

use Psr\Clock\ClockInterface;

/**
 * D-24: derives the UTC boundary instant a concert flips from `upcoming` to `past`, and the status
 * itself. `Concert::$pastAfter` is a deterministic function of `date` + `timezone`, recomputed on
 * every write (creation, AC-1.x; and update, AC-5.4) — never a stale flag.
 *
 * The rule: a concert is `upcoming` until midnight at the end of its own local calendar date, in
 * its own timezone — not the viewer's (see the spec's rationale). `pastAfter` is therefore
 * `(date + 1 day) at 00:00:00` in `$timezone`, converted to UTC, so status is a single indexed
 * comparison (`pastAfter <= now()`) with no per-row timezone math at query time (AC-3.7).
 */
final readonly class ConcertScheduler
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /** `$date` must be a date-only value (time-of-day is ignored); `$timezone` a valid IANA identifier. */
    public function computePastAfter(\DateTimeImmutable $date, string $timezone): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($timezone);

        $localMidnightOfNextDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), $tz);
        \assert(false !== $localMidnightOfNextDay);

        $localMidnightOfNextDay = $localMidnightOfNextDay->modify('+1 day');

        return $localMidnightOfNextDay->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return 'upcoming'|'past' */
    public function status(\DateTimeImmutable $pastAfter): string
    {
        return $pastAfter <= $this->clock->now() ? 'past' : 'upcoming';
    }
}
