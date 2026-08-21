<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use App\Entity\Band;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-6, AC-11.1, AC-11.5: delete + band survival.
 */
final class ConcertDeleteTest extends ConcertWebTestCase
{
    public function testDeleteRemovesTheConcertFromSubsequentReadsAndListings(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $created = $this->createConcert($client, $auth['accessToken'], self::minimalConcertPayload());

        $client->request('DELETE', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($auth['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($auth['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', '/api/concerts', server: self::authHeaders($auth['accessToken']));
        $collection = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(0, $collection['totalItems']);
    }

    public function testDeletingTheLastConcertReferencingABandLeavesTheBandRowIntact(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $bandName = self::uniqueBandName('Survivor');
        $created = $this->createConcert($client, $auth['accessToken'], [
            ...self::minimalConcertPayload(bandName: $bandName),
        ]);
        $bandId = self::bandIdOf(self::lineupEntryAt($created, 0));

        $client->request('DELETE', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($auth['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $band = $em->getRepository(Band::class)->find($bandId);

        self::assertInstanceOf(Band::class, $band, 'AC-6.3: Band rows are never deleted, even when the deleted concert was the last reference');
        self::assertSame($bandName, $band->getName());
    }

    public function testDeletingSomeoneElsesConcertReturns404(): void
    {
        $client = $this->createAuthClient();
        $owner = $this->registerAndLogin($client);
        $created = $this->createConcert($client, $owner['accessToken'], self::minimalConcertPayload());

        $intruder = $this->registerAndLogin($client);
        $client->request('DELETE', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($intruder['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Concert must still exist for its real owner.
        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($created)), server: self::authHeaders($owner['accessToken']));
        self::assertResponseIsSuccessful();
    }

    public function testDeletingANonExistentConcertReturns404(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $client->request('DELETE', '/api/concerts/999999999', server: self::authHeaders($auth['accessToken']));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
