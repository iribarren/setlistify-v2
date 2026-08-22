<?php

declare(strict_types=1);

namespace App\Tests\Functional\Setlist;

use Symfony\Component\HttpFoundation\Response;

/** US-4: `GET /api/setlists/{setlistfmId}`. */
final class SetlistDetailApiTest extends SetlistApiWebTestCase
{
    public function testRequiresAuthentication(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/setlists/63de4613', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturnsSongsWithCoversTapeAndEncoresPreserved(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $this->mockSetlistfmTransport($client, [self::fixtureResponse('setlist-detail-covers-tape-encores.json')]);

        $client->request('GET', '/api/setlists/63de4613', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('found', $data['state']);
        self::assertFalse($data['isEmpty']);
        $songs = $data['songs'];
        self::assertIsArray($songs);
        self::assertCount(8, $songs);

        $tape = $songs[7];
        self::assertIsArray($tape);
        self::assertTrue($tape['isTape']);
    }

    public function testEmptySetlistIsFoundWithExplicitIsEmptyTrue(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $this->mockSetlistfmTransport($client, [self::fixtureResponse('setlist-detail-empty.json')]);

        $client->request('GET', '/api/setlists/7be100aa', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('found', $data['state']);
        self::assertTrue($data['isEmpty']);
        self::assertSame([], $data['songs']);
    }

    public function testUnknownSetlistfmIdIsNotFoundState(): void
    {
        $client = $this->createAuthClient();
        $user = $this->registerAndLogin($client);
        $this->mockSetlistfmTransport($client, [new \Symfony\Component\HttpClient\Response\MockResponse('{"message":"not found"}', ['http_code' => 404])]);

        $client->request('GET', '/api/setlists/doesnotexist123', server: [
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$user['accessToken'],
        ]);

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('not_found', $data['state']);
    }
}
