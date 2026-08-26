<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use App\Entity\ConcertReview;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ConcertReview\ConcertReviewRaceInjector;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC-3.4: two simultaneous first-time `PUT`s for the same concert — one wins, the other is retried
 * once on unique violation and lands as an update. Neither returns a 500.
 *
 * See `App\Tests\Support\ConcertReview\ConcertReviewRaceInjector`'s docblock for why this simulates
 * the race from inside a real request (a `doctrine.event_listener` armed just before the `PUT`)
 * rather than attempting genuine process concurrency, which PHPUnit cannot express.
 */
final class ConcertReviewConcurrencyTest extends ConcertReviewWebTestCase
{
    protected function tearDown(): void
    {
        $this->raceInjector()->disarm();

        parent::tearDown();
    }

    private function raceInjector(): ConcertReviewRaceInjector
    {
        $injector = static::getContainer()->get(ConcertReviewRaceInjector::class);
        self::assertInstanceOf(ConcertReviewRaceInjector::class, $injector);

        return $injector;
    }

    public function testALostRaceOnFirstWriteRetriesAsAnUpdateInsteadOfA500(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);
        $concertId = self::idOf($concert);

        /** @var User $owner */
        $owner = static::getContainer()->get(UserRepository::class)->findOneByEmail($auth['email']);
        $ownerId = $owner->getId();
        self::assertNotNull($ownerId);

        $this->raceInjector()->arm($ownerId, $concertId);

        $client->request(
            'PUT',
            \sprintf('/api/concerts/%d/review', $concertId),
            server: self::authHeaders($auth['accessToken']),
            content: json_encode(['rating' => 5, 'notes' => 'This request lost the race.'], \JSON_THROW_ON_ERROR),
        );

        // The "concurrent" winner's row already existed by the time this request's own insert was
        // attempted — so THIS request's outcome is an update (200), never a 500, and never a 201
        // (it did not create the row).
        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(5, $data['rating']);
        self::assertSame('This request lost the race.', $data['notes']);

        // Exactly one row exists for the pair — the retry updated the winner's row, it did not
        // create a second one (which the unique index would reject anyway).
        $em = $this->entityManager();
        $rows = $em->getRepository(ConcertReview::class)->findBy(['owner' => $ownerId]);
        self::assertCount(1, $rows);
        self::assertSame(5, $rows[0]->getRating());
    }
}
