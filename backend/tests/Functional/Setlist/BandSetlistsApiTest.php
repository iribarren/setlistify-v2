<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setlist;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-3, US-2, US-5: `GET /api/bands/{bandId}/setlists`.
 *
 * Band names are unique per test (random suffix) — the suite shares one database across every test
 * class and `bands.normalized_name` is unique. Search responses that must name-match the band are
 * built inline; `artist-setlists-large-index.json` (AC-13.4's static fixture) has no such coupling,
 * so it's used as-is for the pagination test.
 */
final class BandSetlistsApiTest extends SetlistApiWebTestCase
{
    public function testUnknownBandIsNotFound(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);

        $client->request('GET', '/api/bands/999999999/setlists', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testResolvesAmbiguousBandAndReturnsCandidatesInsteadOfAnEmptyPage(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $name = self::uniqueName('Ambiguous Band');
        $bandId = $this->createBandViaConcert($client, $user['accessToken'], $name);

        $this->mockSetlistfmTransport($client, [self::searchResponse([
            ['mbid' => self::uniqueMbid(), 'name' => $name],
            ['mbid' => self::uniqueMbid(), 'name' => $name],
        ])]);

        $client->request('GET', "/api/bands/{$bandId}/setlists", server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('ambiguous', $data['state']);
        self::assertSame([], $data['setlists']);
        $candidates = $data['candidates'];
        self::assertIsArray($candidates);
        self::assertCount(2, $candidates);
    }

    public function testResolvesNoPresenceBandAndReturnsExplicitStateNotAnEmptyPage(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $bandId = $this->createBandViaConcert($client, $user['accessToken'], self::uniqueName('Unknown Band'));

        $this->mockSetlistfmTransport($client, [self::fixtureResponse('artist-search-empty.json')]);

        $client->request('GET', "/api/bands/{$bandId}/setlists", server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('no_presence', $data['state']);
        self::assertSame([], $data['setlists']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testResolvedBandReturnsPaginatedSetlistsFromTheCachedIndex(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $name = self::uniqueName('Resolved Band');
        $bandId = $this->createBandViaConcert($client, $user['accessToken'], $name);

        $this->mockSetlistfmTransport($client, [
            self::searchResponse([['mbid' => self::uniqueMbid(), 'name' => $name]]),
            self::fixtureResponse('artist-setlists-large-index.json'),
        ]);

        $client->request('GET', "/api/bands/{$bandId}/setlists?page=1&itemsPerPage=10", server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('resolved', $data['state']);
        self::assertSame(10, \count($data['setlists']));
        self::assertSame(20, $data['totalItems']);
        self::assertIsArray($data['freshness']);
    }

    private static function uniqueName(string $label): string
    {
        return \sprintf('%s %s', $label, bin2hex(random_bytes(6)));
    }

    private static function uniqueMbid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @param list<array{mbid: string, name: string}> $candidates */
    private static function searchResponse(array $candidates): MockResponse
    {
        $artists = array_map(static fn (array $c): array => [
            'mbid' => $c['mbid'],
            'name' => $c['name'],
            'sortName' => $c['name'],
            'disambiguation' => '',
            'url' => 'https://www.setlist.fm/setlists/test.html',
        ], $candidates);

        $body = json_encode([
            'type' => 'artists',
            'itemsPerPage' => 20,
            'page' => 1,
            'total' => \count($artists),
            'artist' => $artists,
        ], \JSON_THROW_ON_ERROR);

        return new MockResponse($body, ['http_code' => 200]);
    }
}
