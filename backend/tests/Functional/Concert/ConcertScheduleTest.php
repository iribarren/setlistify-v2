<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * D-24, AC-3.6, AC-11.3: a concert dated *today* is `upcoming` until the end of its own local
 * calendar day in its own timezone, `past` from that moment on — never relative to the viewer.
 * Pinned at three clock points, including a concert in `Pacific/Auckland` evaluated from a client
 * that itself would be in `America/Los_Angeles` (the server never cares about the client's
 * timezone — there is nothing to configure per-request, so this is really "does the same concert
 * answer the same way regardless of when in UTC we ask").
 */
final class ConcertScheduleTest extends ConcertWebTestCase
{
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testConcertIsUpcomingUntilTheInstantBeforeLocalMidnight(): void
    {
        // 2026-06-15 in Europe/Madrid (UTC+2 in June) ends at 2026-06-16T00:00:00+02:00 = 2026-06-15T22:00:00Z.
        Clock::set(new MockClock('2026-06-15T21:59:59+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $data = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-06-15', 'Europe/Madrid'));

        self::assertSame('upcoming', $data['status']);
    }

    public function testConcertIsPastFromTheInstantOfLocalMidnightOnward(): void
    {
        Clock::set(new MockClock('2026-06-15T22:00:00+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $data = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-06-15', 'Europe/Madrid'));

        self::assertSame('past', $data['status']);
    }

    public function testStatusIsTheSameRegardlessOfWhichTimezoneEvaluatesIt(): void
    {
        // 2026-06-15 in Pacific/Auckland (UTC+12) ends at 2026-06-16T00:00:00+12:00 = 2026-06-15T12:00:00Z.
        // Just before that instant: upcoming.
        Clock::set(new MockClock('2026-06-15T11:59:59+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $before = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-06-15', 'Pacific/Auckland'));
        self::assertSame('upcoming', $before['status'], 'still upcoming one second before local midnight in Pacific/Auckland');

        // Just after: past. The server holds no notion of "a client in America/Los_Angeles" — the
        // rule is anchored to the concert's own timezone, not any viewer's, so evaluating the same
        // concert at the same UTC instant always gives the same answer regardless of who's asking.
        Clock::set(new MockClock('2026-06-15T12:00:01+00:00'));
        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($before)), server: self::authHeaders($auth['accessToken']));
        self::assertResponseIsSuccessful();
        $after = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('past', $after['status']);
    }

    public function testPastAfterIsRederivedOnUpdate(): void
    {
        Clock::set(new MockClock('2026-01-01T00:00:00+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $created = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-06-15', 'UTC'));
        self::assertSame('upcoming', $created['status']);

        // Move the clock past the original date's boundary, then patch the timezone only —
        // pastAfter must be recomputed from the (unchanged) date + new timezone (AC-5.4).
        Clock::set(new MockClock('2026-06-16T01:00:00+00:00'));

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['timezone' => 'Pacific/Auckland'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('Pacific/Auckland', $data['timezone']);
    }
}
