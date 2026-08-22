<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setlist;

use Symfony\Component\HttpFoundation\Response;

/** US-1: `GET /api/band-searches?name=`. */
final class BandSearchApiTest extends SetlistApiWebTestCase
{
    public function testRequiresAuthentication(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/band-searches?name=Radiohead', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturnsCandidatesInSetlistfmOrderWithFreshnessEnvelope(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $this->mockSetlistfmTransport($client, [self::fixtureResponse('artist-search-multi-candidate.json')]);

        $client->request('GET', '/api/band-searches?name=Nirvana', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $candidates = $data['candidates'];
        self::assertIsArray($candidates);
        self::assertCount(3, $candidates);
        $first = $candidates[0];
        self::assertIsArray($first);
        self::assertSame('5b11f4ce-a62d-471e-81fc-a69a8278c7da', $first['mbid']);

        $freshness = $data['freshness'];
        self::assertIsArray($freshness);
        self::assertSame('live', $freshness['source']);
        self::assertFalse($freshness['stale']);
        self::assertNull($freshness['reason']);
    }

    public function testBlankNameIsRejected(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);

        $client->request('GET', '/api/band-searches?name=', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
