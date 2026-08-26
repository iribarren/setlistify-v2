<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-1 through US-5, AC-11.2: CRUD happy paths for `/api/concerts/{concertId}/review`.
 */
final class ConcertReviewCrudTest extends ConcertReviewWebTestCase
{
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testFirstWriteReturns201AndSecondWriteReturns200(): void
    {
        Clock::set(new MockClock('2026-01-01T10:00:00+00:00'));

        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $created = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 4], Response::HTTP_CREATED);
        self::assertSame(4, $created['rating']);
        self::assertNull($created['notes']);

        Clock::set(new MockClock('2026-01-01T10:05:00+00:00'));

        $updated = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 5, 'notes' => 'Even better on reflection.']);
        self::assertSame(5, $updated['rating']);
        self::assertSame('Even better on reflection.', $updated['notes']);

        // AC-2.6: updatedAt changes, createdAt does not.
        self::assertSame($created['createdAt'], $updated['createdAt']);
        self::assertNotSame($created['updatedAt'], $updated['updatedAt']);
    }

    public function testRatingOnlyReviewSaves(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 3], Response::HTTP_CREATED);
        self::assertSame(3, $review['rating']);
        self::assertNull($review['notes']);
    }

    public function testNotesOnlyReviewSaves(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['notes' => 'Loud. Great encore.'], Response::HTTP_CREATED);
        self::assertNull($review['rating']);
        self::assertSame('Loud. Great encore.', $review['notes']);
    }

    public function testGetReturns404BeforeAnyWrite(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $this->getReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetReturnsTheSavedReview(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);
        $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 2, 'notes' => 'Meh.'], Response::HTTP_CREATED);

        $this->getReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(2, $data['rating']);
        self::assertSame('Meh.', $data['notes']);
    }

    public function testDeleteRemovesTheReviewAndSubsequentGetIs404(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);
        $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 1], Response::HTTP_CREATED);

        $this->deleteReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteOnAConcertWithNoReviewReturns404(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $this->deleteReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testEditingAndDeletingAreNeverBlockedByThePastOnlyRuleEvenIfConcertIsNowUpcoming(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);
        $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 4], Response::HTTP_CREATED);

        // Move the concert's date into the future (D-235's exemption 2/3: editing/deleting a review
        // is never blocked, even if the concert's own status has since become "upcoming").
        $patchHeaders = array_merge(self::authHeaders($auth['accessToken']), ['CONTENT_TYPE' => 'application/merge-patch+json']);
        $client->request('PATCH', \sprintf('/api/concerts/%d', self::idOf($concert)), server: $patchHeaders, content: json_encode(['date' => self::FUTURE_DATE], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 5]);
        self::assertResponseIsSuccessful();

        $this->deleteReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }
}
