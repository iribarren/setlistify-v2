<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use App\Entity\Band;
use App\Entity\Setlist;
use App\Entity\Song;
use App\Repository\BandRepository;
use App\Tests\Functional\Concert\ConcertWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared scaffolding for notes-and-reviews functional tests
 * (docs/specs/2026-08-26-notes-and-reviews.md). Extends `App\Tests\Functional\Concert\ConcertWebTestCase`
 * to reuse its auth/concert helpers — every review test needs an authenticated user and a concert
 * first.
 */
abstract class ConcertReviewWebTestCase extends ConcertWebTestCase
{
    /** A concert date safely in the past, regardless of when the suite runs (D-234's gate needs one). */
    protected const string PAST_DATE = '2020-01-01';

    /** A concert date safely in the future, but within the 5-year create-time cap (D-31, AC-9.2). */
    protected const string FUTURE_DATE = '2028-12-24';

    /** @return array<string, mixed> the created concert */
    protected function createPastConcert(KernelBrowser $client, string $accessToken, ?string $bandName = null): array
    {
        return $this->createConcert($client, $accessToken, self::minimalConcertPayload(self::PAST_DATE, bandName: $bandName));
    }

    /** @return array<string, mixed> the created concert */
    protected function createUpcomingConcert(KernelBrowser $client, string $accessToken, ?string $bandName = null): array
    {
        return $this->createConcert($client, $accessToken, self::minimalConcertPayload(self::FUTURE_DATE, bandName: $bandName));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function putReview(KernelBrowser $client, string $accessToken, int $concertId, array $payload, int $expectedStatus = Response::HTTP_OK): array
    {
        $client->request(
            'PUT',
            \sprintf('/api/concerts/%d/review', $concertId),
            server: self::authHeaders($accessToken),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame($expectedStatus, (string) $client->getResponse()->getContent());

        return self::decodeJsonObject((string) $client->getResponse()->getContent());
    }

    protected function getReview(KernelBrowser $client, string $accessToken, int $concertId): void
    {
        $client->request('GET', \sprintf('/api/concerts/%d/review', $concertId), server: self::authHeaders($accessToken));
    }

    protected function deleteReview(KernelBrowser $client, string $accessToken, int $concertId): void
    {
        $client->request('DELETE', \sprintf('/api/concerts/%d/review', $concertId), server: self::authHeaders($accessToken));
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** A `Song` belonging to a fresh `Setlist` for the band whose name is `$bandName` (must already exist). */
    protected function persistSongForBand(string $bandName, string $title = 'Highlight Song'): Song
    {
        $em = $this->entityManager();
        $now = new \DateTimeImmutable();

        $bandRepository = static::getContainer()->get(BandRepository::class);
        $band = $bandRepository->findOneBy(['name' => $bandName]);
        self::assertInstanceOf(Band::class, $band, \sprintf('Band "%s" must exist.', $bandName));

        $setlist = new Setlist('sl-'.bin2hex(random_bytes(6)), $band, new \DateTimeImmutable('2019-06-01'), 'Test Venue', 'Test City', 'ES', null, $now);
        $em->persist($setlist);
        $song = new Song($setlist, 0, null, $title, null, null, null, null, false);
        $setlist->addSong($song);
        $em->persist($song);
        $em->flush();

        return $song;
    }

    /** A `Song` for a brand-new band that is NOT in any concert's lineup — for highlight-scope-violation tests. */
    protected function persistSongForNewUnrelatedBand(string $title = 'Out Of Scope Song'): Song
    {
        $em = $this->entityManager();
        $now = new \DateTimeImmutable();

        $band = new Band('Unrelated Band '.bin2hex(random_bytes(6)), 'unrelated band '.bin2hex(random_bytes(6)), $now);
        $em->persist($band);

        $setlist = new Setlist('sl-'.bin2hex(random_bytes(6)), $band, new \DateTimeImmutable('2019-06-01'), 'Test Venue', 'Test City', 'ES', null, $now);
        $em->persist($setlist);
        $song = new Song($setlist, 0, null, $title, null, null, null, null, false);
        $setlist->addSong($song);
        $em->persist($song);
        $em->flush();

        return $song;
    }
}
