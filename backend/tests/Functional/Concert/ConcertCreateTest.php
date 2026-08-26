<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use Symfony\Component\HttpFoundation\Response;

/**
 * US-1, US-2, AC-11.1: multi-band lineup create + read-back order, optional-field round-trip.
 */
final class ConcertCreateTest extends ConcertWebTestCase
{
    public function testLineupOrderIsPreservedOnCreateAndReadBack(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $headliner = self::uniqueBandName('Headliner');
        $support = self::uniqueBandName('Support');
        $opener = self::uniqueBandName('Opener');

        $data = $this->createConcert($client, $auth['accessToken'], [
            'date' => '2026-12-24',
            'timezone' => 'Europe/Madrid',
            'lineup' => [['name' => $headliner], ['name' => $support], ['name' => $opener]],
        ]);

        self::assertCount(3, self::asArray($data['lineup']));
        self::assertSame($headliner, self::bandNameOf(self::lineupEntryAt($data, 0)));
        self::assertSame(0, self::billingOrderOf(self::lineupEntryAt($data, 0)));
        self::assertSame($support, self::bandNameOf(self::lineupEntryAt($data, 1)));
        self::assertSame(1, self::billingOrderOf(self::lineupEntryAt($data, 1)));
        self::assertSame($opener, self::bandNameOf(self::lineupEntryAt($data, 2)));
        self::assertSame(2, self::billingOrderOf(self::lineupEntryAt($data, 2)));

        // Read back via item GET — order must still hold (AC-1.4), not just in the create response.
        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($data)), server: self::authHeaders($auth['accessToken']));
        self::assertResponseIsSuccessful();
        $reread = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame($headliner, self::bandNameOf(self::lineupEntryAt($reread, 0)));
        self::assertSame($support, self::bandNameOf(self::lineupEntryAt($reread, 1)));
        self::assertSame($opener, self::bandNameOf(self::lineupEntryAt($reread, 2)));
    }

    public function testLineupEntryShapeIsNeverABareString(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $data = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());
        $entry = self::lineupEntryAt($data, 0);

        self::assertArrayHasKey('band', $entry);
        self::assertArrayHasKey('id', self::asArray($entry['band']));
        self::assertArrayHasKey('name', self::asArray($entry['band']));
        self::assertArrayHasKey('billingOrder', $entry);
    }

    public function testOptionalFieldsRoundTripAndOmittedOnesComeBackNull(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $data = $this->createConcert($client, $auth['accessToken'], [
            'date' => '2026-06-15',
            'timezone' => 'Europe/Madrid',
            'lineup' => [['name' => self::uniqueBandName()]],
            'venue' => ['name' => 'Wizink Center', 'city' => 'Madrid', 'countryCode' => 'es'],
            'ticketPrice' => ['amount' => 4500, 'currency' => 'eur'],
            'doorsTime' => '19:00',
            'startTime' => '20:30',
        ]);

        $venue = self::asArray($data['venue']);
        $ticketPrice = self::asArray($data['ticketPrice']);

        self::assertSame('Wizink Center', $venue['name']);
        self::assertSame('Madrid', $venue['city']);
        self::assertSame('ES', $venue['countryCode'], 'countryCode is uppercased on write (D-26)');
        self::assertSame(4500, $ticketPrice['amount']);
        self::assertSame('EUR', $ticketPrice['currency'], 'currency is uppercased on write (D-28)');
        self::assertSame('19:00', $data['doorsTime']);
        self::assertSame('20:30', $data['startTime']);
    }

    public function testAMinimalConcertWithOnlyDateAndOneBandIsValid(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $data = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());

        $venue = self::asArray($data['venue']);
        self::assertNull($venue['name']);
        self::assertNull($venue['city']);
        self::assertNull($venue['countryCode']);
        self::assertNull($data['ticketPrice']);
        self::assertNull($data['doorsTime']);
        self::assertNull($data['startTime']);
    }

    public function testZeroAmountTicketPriceIsValid(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $data = $this->createConcert($client, $auth['accessToken'], [
            ...self::minimalConcertPayload(),
            'ticketPrice' => ['amount' => 0, 'currency' => 'USD'],
        ]);

        self::assertSame(0, self::asArray($data['ticketPrice'])['amount']);
    }

    public function testOwnerIdCreatedAtAndStatusInPayloadAreIgnored(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $data = $this->createConcert($client, $auth['accessToken'], [
            ...self::minimalConcertPayload(),
            'id' => 999999,
            'owner' => 123,
            'status' => 'past',
            'createdAt' => '1999-01-01T00:00:00+00:00',
        ]);

        self::assertNotSame(999999, $data['id']);
        self::assertSame('upcoming', $data['status'], 'AC-7.4/AC-1.2: status/owner/id cannot be set from the payload');
    }

    public function testResponseNeverExposesRolesOrOwnerField(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $data = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());

        self::assertArrayNotHasKey('owner', $data);
    }

    public function testCreatingAConcertRequiresAuthentication(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            '/api/concerts',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(self::minimalConcertPayload(), \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
