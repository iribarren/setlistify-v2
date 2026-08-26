<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-5, AC-11.1: PATCH including lineup replacement.
 */
final class ConcertUpdateTest extends ConcertWebTestCase
{
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testPatchUpdatesOnlyTheSuppliedFields(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $created = $this->createConcert($client, $auth['accessToken'], [
            ...self::minimalConcertPayload('2026-06-15', 'Europe/Madrid'),
            'doorsTime' => '18:00',
        ]);

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['doorsTime' => '19:30'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('19:30', $data['doorsTime']);
        self::assertSame('2026-06-15', $data['date'], 'date was not in the patch, must stay untouched');
        self::assertSame(self::bandNameOf(self::lineupEntryAt($created, 0)), self::bandNameOf(self::lineupEntryAt($data, 0)));
    }

    public function testPatchReplacesTheWholeLineupAndRederivesBillingOrder(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $keptBand = self::uniqueBandName('Kept');
        $created = $this->createConcert($client, $auth['accessToken'], [
            ...self::minimalConcertPayload(),
            'lineup' => [['name' => self::uniqueBandName('Dropped')], ['name' => $keptBand]],
        ]);

        $newSupport = self::uniqueBandName('NewSupport');

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['lineup' => [['name' => $keptBand], ['name' => $newSupport]]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertCount(2, self::asArray($data['lineup']));
        self::assertSame($keptBand, self::bandNameOf(self::lineupEntryAt($data, 0)));
        self::assertSame(0, self::billingOrderOf(self::lineupEntryAt($data, 0)));
        self::assertSame($newSupport, self::bandNameOf(self::lineupEntryAt($data, 1)));
        self::assertSame(1, self::billingOrderOf(self::lineupEntryAt($data, 1)));
    }

    public function testChangingDateOrTimezoneRederivesStatusImmediately(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $created = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2030-01-01', 'UTC'));
        self::assertSame('upcoming', $created['status']);

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['date' => '2020-01-01'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame('past', $data['status']);
    }

    public function testUnwritableFieldsInPatchBodyAreIgnored(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $created = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['status' => 'past', 'createdAt' => '1999-01-01T00:00:00+00:00'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame($created['id'], $data['id']);
        self::assertSame($created['status'], $data['status']);
        self::assertSame($created['createdAt'], $data['createdAt']);
    }

    /**
     * `ConcertPatchInput` has no `id` property (AC-5.5), so it cannot be bound to. A raw client that
     * sends one anyway does not get to hijack a different concert either — API Platform's generic
     * item-normalizer treats a bare `id` in the body as "resolve this as this resource's own IRI",
     * which fails closed (400) rather than silently repointing the update at another row.
     */
    public function testIdInPatchBodyNeverRepointsTheUpdate(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $created = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());
        $other = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload('2026-02-01'));

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['id' => self::idOf($other), 'doorsTime' => '18:00'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Neither concert was mutated.
        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($other)), server: self::authHeaders($auth['accessToken']));
        $otherReread = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertNull($otherReread['doorsTime']);
    }

    public function testUpdatedAtChangesButCreatedAtDoesNot(): void
    {
        Clock::set(new MockClock('2026-01-01T10:00:00+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $created = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());

        Clock::set(new MockClock('2026-01-01T10:05:00+00:00'));

        $client->request(
            'PATCH',
            \sprintf('/api/concerts/%d', self::idOf($created)),
            server: array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']),
            content: json_encode(['doorsTime' => '18:00'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame($created['createdAt'], $data['createdAt']);
        self::assertNotSame($created['updatedAt'], $data['updatedAt']);
    }
}
