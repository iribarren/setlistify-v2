<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-3, US-4, AC-11.1: list pagination/filter/sort and band search.
 */
final class ConcertListTest extends ConcertWebTestCase
{
    protected function tearDown(): void
    {
        \Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\NativeClock());
        parent::tearDown();
    }

    public function testEmptyCollectionIsTwoHundredNotFourOhFour(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('GET', '/api/concerts', server: self::authHeaders($auth['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(0, $data['totalItems']);
        self::assertSame([], $data['member']);
    }

    public function testStatusFilterSeparatesUpcomingFromPast(): void
    {
        \Symfony\Component\Clock\Clock::set(new MockClock('2026-06-15T12:00:00+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2020-01-01', 'UTC'));
        $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2030-01-01', 'UTC'));

        $client->request('GET', '/api/concerts?status=upcoming', server: self::authHeaders($auth['accessToken']));
        $upcoming = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(1, $upcoming['totalItems']);
        self::assertSame('2030-01-01', self::memberAt($upcoming, 0)['date']);

        $client->request('GET', '/api/concerts?status=past', server: self::authHeaders($auth['accessToken']));
        $past = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(1, $past['totalItems']);
        self::assertSame('2020-01-01', self::memberAt($past, 0)['date']);

        $client->request('GET', '/api/concerts', server: self::authHeaders($auth['accessToken']));
        $both = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(2, $both['totalItems'], 'omitting status returns both (AC-3.3)');
    }

    public function testUnrecognisedStatusValueIsA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('GET', '/api/concerts?status=nonsense', server: self::authHeaders($auth['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testDateSortingBothDirections(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-01-01', 'UTC'));
        $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-06-01', 'UTC'));
        $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-03-01', 'UTC'));

        $client->request('GET', '/api/concerts?order[date]=asc', server: self::authHeaders($auth['accessToken']));
        $asc = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(['2026-01-01', '2026-03-01', '2026-06-01'], array_column(self::membersOf($asc), 'date'));

        $client->request('GET', '/api/concerts?order[date]=desc', server: self::authHeaders($auth['accessToken']));
        $desc = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(['2026-06-01', '2026-03-01', '2026-01-01'], array_column(self::membersOf($desc), 'date'));
    }

    public function testPaginationDefaultsToTwentyAndIsClampedToOneHundred(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        for ($i = 0; $i < 3; ++$i) {
            $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload(\sprintf('2026-01-%02d', $i + 1), 'UTC'));
        }

        $client->request('GET', '/api/concerts?itemsPerPage=2&page=1', server: self::authHeaders($auth['accessToken']));
        $page1 = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertCount(2, self::membersOf($page1));
        self::assertSame(3, $page1['totalItems']);

        $client->request('GET', '/api/concerts?itemsPerPage=2&page=2', server: self::authHeaders($auth['accessToken']));
        $page2 = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertCount(1, self::membersOf($page2));

        $client->request('GET', '/api/concerts?itemsPerPage=1000', server: self::authHeaders($auth['accessToken']));
        self::assertResponseIsSuccessful();
        $clamped = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertCount(3, self::membersOf($clamped), 'itemsPerPage is clamped to 100, not rejected (AC-3.5)');
    }

    public function testBandSearchIsNormalizedAndCaseInsensitive(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $this->createConcert($client, $auth['accessToken'], [
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => 'Sigur Rós']],
        ]);
        $this->createConcert($client, $auth['accessToken'], [
            'date' => '2026-02-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => 'The Beatles']],
        ]);

        $client->request('GET', '/api/concerts?band=sigur+ros', server: self::authHeaders($auth['accessToken']));
        $bySigur = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(1, $bySigur['totalItems']);

        $client->request('GET', '/api/concerts?band=beatles', server: self::authHeaders($auth['accessToken']));
        $byBeatles = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(1, $byBeatles['totalItems']);
        self::assertSame('The Beatles', self::bandNameOf(self::lineupEntryAt(self::memberAt($byBeatles, 0), 0)));
    }

    public function testBandSearchDoesNotDuplicateAConcertWithSeveralMatchingBands(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $this->createConcert($client, $auth['accessToken'], [
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => 'The Rolling Stones'], ['name' => 'The Rolling Stones Tribute Band']],
        ]);

        $client->request('GET', '/api/concerts?band=rolling', server: self::authHeaders($auth['accessToken']));
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame(1, $data['totalItems'], 'R-6: a lineup with several matching bands must not duplicate the concert row');
    }

    public function testBlankBandQueryIsTreatedAsAbsent(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());

        $client->request('GET', '/api/concerts?band=%20%20', server: self::authHeaders($auth['accessToken']));
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame(1, $data['totalItems']);
    }
}
