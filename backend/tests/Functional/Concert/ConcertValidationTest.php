<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use Symfony\Component\HttpFoundation\Response;

/**
 * US-9, AC-11.6: every 422 case is `application/problem+json` with a `violations` array of
 * `{ propertyPath, message }` (AC-9.1).
 */
final class ConcertValidationTest extends ConcertWebTestCase
{
    /** @param array<string, mixed> $payload */
    private function assertConcertRequestIsA422WithViolationAt(array $payload, string $expectedPropertyPath): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('POST', '/api/concerts', server: self::authHeaders($auth['accessToken']), content: json_encode($payload, \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());
        self::assertStringContainsString('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertArrayHasKey('violations', $data);
        $violations = self::asArray($data['violations']);
        self::assertNotEmpty($violations);

        $paths = array_column($violations, 'propertyPath');
        self::assertContains($expectedPropertyPath, $paths);

        foreach ($violations as $violation) {
            $violation = self::asArray($violation);
            self::assertArrayHasKey('propertyPath', $violation);
            self::assertArrayHasKey('message', $violation);
        }
    }

    public function testDateBeforeMinimumIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'date' => '1899-12-31',
        ], 'date');
    }

    public function testDateMoreThanFiveYearsAheadIsA422(): void
    {
        $farFuture = (new \DateTimeImmutable('+6 years'))->format('Y-m-d');

        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'date' => $farFuture,
        ], 'date');
    }

    public function testMalformedDateIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'date' => '2026-02-30',
        ], 'date');
    }

    public function testMissingDateIsA422(): void
    {
        $payload = self::minimalConcertPayload();
        unset($payload['date']);

        $this->assertConcertRequestIsA422WithViolationAt($payload, 'date');
    }

    public function testInvalidTimezoneIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'timezone' => 'Not/AZone',
        ], 'timezone');
    }

    public function testFixedOffsetTimezoneIsRejected(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'timezone' => '+02:00',
        ], 'timezone');
    }

    public function testEmptyLineupIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [],
        ], 'lineup');
    }

    public function testMoreThanThirtyBandsIsA422(): void
    {
        $lineup = [];
        for ($i = 0; $i < 31; ++$i) {
            $lineup[] = ['name' => self::uniqueBandName('Band'.$i)];
        }

        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => $lineup,
        ], 'lineup');
    }

    public function testDuplicateBandInLineupIsA422WithIndexedPath(): void
    {
        $name = self::uniqueBandName();

        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => $name], ['name' => strtoupper($name)]],
        ], 'lineup[1]');
    }

    public function testBandNameOverOneHundredTwentyCharactersIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => str_repeat('a', 121)]],
        ], 'lineup[0].name');
    }

    public function testBandNameThatNormalizesToEmptyIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => '---']],
        ], 'lineup[0].name');
    }

    public function testInvalidCountryCodeIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'venue' => ['countryCode' => 'ZZ'],
        ], 'venue.countryCode');
    }

    public function testInvalidCurrencyCodeIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'ticketPrice' => ['amount' => 100, 'currency' => 'ZZZ'],
        ], 'ticketPrice.currency');
    }

    public function testTicketPriceAmountWithoutCurrencyIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'ticketPrice' => ['amount' => 100],
        ], 'ticketPrice');
    }

    public function testTicketPriceCurrencyWithoutAmountIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'ticketPrice' => ['currency' => 'EUR'],
        ], 'ticketPrice');
    }

    public function testNegativeTicketPriceIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'ticketPrice' => ['amount' => -1, 'currency' => 'EUR'],
        ], 'ticketPrice.amount');
    }

    public function testDoorsTimeAfterStartTimeIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'doorsTime' => '21:00',
            'startTime' => '20:00',
        ], 'doorsTime');
    }

    public function testMalformedTimeIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            ...self::minimalConcertPayload(),
            'doorsTime' => '9am',
        ], 'doorsTime');
    }

    public function testMalformedJsonReturns400NotA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('POST', '/api/concerts', server: self::authHeaders($auth['accessToken']), content: '{not valid json');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAmbiguousLineupEntryWithBothNameAndBandIdIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => 'X', 'bandId' => 1]],
        ], 'lineup[0]');
    }

    public function testUnknownBandIdIsA422(): void
    {
        $this->assertConcertRequestIsA422WithViolationAt([
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['bandId' => 999999999]],
        ], 'lineup[0].bandId');
    }
}
