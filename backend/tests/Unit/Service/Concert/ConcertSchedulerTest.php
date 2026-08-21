<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Concert;

use App\Service\Concert\ConcertScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * D-24: `pastAfter` is `(date + 1 day) at 00:00` in the concert's own timezone, converted to UTC.
 */
final class ConcertSchedulerTest extends TestCase
{
    public function testPastAfterIsMidnightAtTheEndOfTheLocalDateInUtc(): void
    {
        $scheduler = new ConcertScheduler(new MockClock('2026-01-01T00:00:00+00:00'));

        $date = new \DateTimeImmutable('2026-06-15');
        $pastAfter = $scheduler->computePastAfter($date, 'Europe/Madrid');

        // 2026-06-16T00:00:00+02:00 (Madrid is UTC+2 in June, DST) = 2026-06-15T22:00:00Z.
        self::assertSame('2026-06-15T22:00:00+00:00', $pastAfter->format('Y-m-d\TH:i:sP'));
    }

    public function testPastAfterAccountsForTheTimezonesOwnOffsetNotUtc(): void
    {
        $scheduler = new ConcertScheduler(new MockClock('2026-01-01T00:00:00+00:00'));

        $date = new \DateTimeImmutable('2026-06-15');
        $pastAfter = $scheduler->computePastAfter($date, 'Pacific/Auckland');

        // 2026-06-16T00:00:00+12:00 = 2026-06-15T12:00:00Z.
        self::assertSame('2026-06-15T12:00:00+00:00', $pastAfter->format('Y-m-d\TH:i:sP'));
    }

    public function testStatusIsUpcomingBeforePastAfterAndPastAtOrAfterIt(): void
    {
        $pastAfter = new \DateTimeImmutable('2026-06-15T22:00:00+00:00');

        $before = new ConcertScheduler(new MockClock('2026-06-15T21:59:59+00:00'));
        self::assertSame('upcoming', $before->status($pastAfter));

        $atBoundary = new ConcertScheduler(new MockClock('2026-06-15T22:00:00+00:00'));
        self::assertSame('past', $atBoundary->status($pastAfter));

        $after = new ConcertScheduler(new MockClock('2026-06-15T22:00:01+00:00'));
        self::assertSame('past', $after->status($pastAfter));
    }
}
