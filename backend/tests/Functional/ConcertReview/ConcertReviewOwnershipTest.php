<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use Symfony\Component\HttpFoundation\Response;

/**
 * D-229, AC-4.1: a request from user B for a concert owned by user A returns 404 — byte-identical
 * to the response for a `concertId` that does not exist. Six cases: GET/PUT/DELETE × {other user's
 * concert, nonexistent concert id}.
 */
final class ConcertReviewOwnershipTest extends ConcertReviewWebTestCase
{
    private const string MISSING_ID = '999999999';

    public function testGetOnSomeoneElsesConcertReturns404IdenticalToMissingId(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $owner['accessToken']);
        $this->putReview($client, $owner['accessToken'], self::idOf($concert), ['rating' => 4], Response::HTTP_CREATED);

        $intruder = $this->registerAndLogin($client);

        $client->request('GET', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('GET', '/api/concerts/'.self::MISSING_ID.'/review', server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);
    }

    public function testGetOnNonexistentConcertReturns404(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('GET', '/api/concerts/'.self::MISSING_ID.'/review', server: self::authHeaders($auth['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPutOnSomeoneElsesConcertReturns404IdenticalToMissingId(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $owner['accessToken']);

        $intruder = $this->registerAndLogin($client);
        $putHeaders = self::authHeaders($intruder['accessToken']);
        $body = json_encode(['rating' => 5], \JSON_THROW_ON_ERROR);

        $client->request('PUT', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: $putHeaders, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('PUT', '/api/concerts/'.self::MISSING_ID.'/review', server: $putHeaders, content: $body);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);

        // The concert must remain unreviewed for its real owner.
        $this->getReview($client, $owner['accessToken'], self::idOf($concert));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPutOnNonexistentConcertReturns404(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('PUT', '/api/concerts/'.self::MISSING_ID.'/review', server: self::authHeaders($auth['accessToken']), content: json_encode(['rating' => 5], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteOnSomeoneElsesConcertReturns404IdenticalToMissingId(): void
    {
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $owner['accessToken']);
        $this->putReview($client, $owner['accessToken'], self::idOf($concert), ['rating' => 4], Response::HTTP_CREATED);

        $intruder = $this->registerAndLogin($client);

        $client->request('DELETE', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('DELETE', '/api/concerts/'.self::MISSING_ID.'/review', server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);

        // The review must survive the intruder's attempt.
        $this->getReview($client, $owner['accessToken'], self::idOf($concert));
        self::assertResponseIsSuccessful();
    }

    public function testDeleteOnNonexistentConcertReturns404(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('DELETE', '/api/concerts/'.self::MISSING_ID.'/review', server: self::authHeaders($auth['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
