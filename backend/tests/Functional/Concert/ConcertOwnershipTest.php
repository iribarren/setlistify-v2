<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use Symfony\Component\HttpFoundation\Response;

/**
 * US-7, AC-11.2: ownership isolation, one test per verb, asserting 404 with a body identical to the
 * missing-id case (AC-7.2) — existence must not leak.
 */
final class ConcertOwnershipTest extends ConcertWebTestCase
{
    private const string MISSING_ID = '999999999';

    public function testGetItemOnSomeoneElsesConcertReturns404IdenticalToMissingId(): void
    {
        // debug:false — the 404 bodies must match byte-for-byte (AC-7.2), and debug mode embeds a
        // per-call-site stack trace that would make the two responses differ for reasons that have
        // nothing to do with what AC-7.2 is actually testing (see ProblemDetailsTest for the same
        // debug:false pattern used for exactly this reason).
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $created = $this->createConcert($client, $owner['accessToken'], self::minimalConcertPayload());

        $intruder = $this->registerAndLogin($client);

        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('GET', '/api/concerts/'.self::MISSING_ID, server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);
    }

    public function testPatchOnSomeoneElsesConcertReturns404IdenticalToMissingId(): void
    {
        // debug:false — the 404 bodies must match byte-for-byte (AC-7.2), and debug mode embeds a
        // per-call-site stack trace that would make the two responses differ for reasons that have
        // nothing to do with what AC-7.2 is actually testing (see ProblemDetailsTest for the same
        // debug:false pattern used for exactly this reason).
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $created = $this->createConcert($client, $owner['accessToken'], self::minimalConcertPayload());

        $intruder = $this->registerAndLogin($client);
        $patchHeaders = array_merge(self::authHeaders($intruder['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']);
        $patchBody = json_encode(['note' => 'hijacked'], \JSON_THROW_ON_ERROR);

        $client->request('PATCH', \sprintf('/api/concerts/%d', self::idOf($created)), server: $patchHeaders, content: $patchBody);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('PATCH', '/api/concerts/'.self::MISSING_ID, server: $patchHeaders, content: $patchBody);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);

        // The concert itself must be untouched.
        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($owner['accessToken']));
        $stillOwners = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertNull($stillOwners['note']);
    }

    public function testDeleteOnSomeoneElsesConcertReturns404IdenticalToMissingId(): void
    {
        // debug:false — the 404 bodies must match byte-for-byte (AC-7.2), and debug mode embeds a
        // per-call-site stack trace that would make the two responses differ for reasons that have
        // nothing to do with what AC-7.2 is actually testing (see ProblemDetailsTest for the same
        // debug:false pattern used for exactly this reason).
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $created = $this->createConcert($client, $owner['accessToken'], self::minimalConcertPayload());

        $intruder = $this->registerAndLogin($client);

        $client->request('DELETE', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('DELETE', '/api/concerts/'.self::MISSING_ID, server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);
    }

    public function testAnonymousRequestToConcertEndpointsReturns401(): void
    {
        $client = $this->createAuthClient();

        $client->request('GET', '/api/concerts');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->request('GET', '/api/concerts/1');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCollectionCountExcludesOtherUsersConcerts(): void
    {
        $client = $this->createAuthClient();
        $userA = $this->registerAndLogin($client);

        for ($i = 0; $i < 7; ++$i) {
            $this->createConcert($client, $userA['accessToken'], self::minimalConcertPayload(\sprintf('2026-01-%02d', $i + 1)));
        }

        $userB = $this->registerAndLogin($client);
        for ($i = 0; $i < 3; ++$i) {
            $this->createConcert($client, $userB['accessToken'], self::minimalConcertPayload(\sprintf('2026-02-%02d', $i + 1)));
        }

        $client->request('GET', '/api/concerts', server: self::authHeaders($userA['accessToken']));
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame(7, $data['totalItems'], 'AC-7.6: the Hydra total must not include another user\'s rows');
    }
}
