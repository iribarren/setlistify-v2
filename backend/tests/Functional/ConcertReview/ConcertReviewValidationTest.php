<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use Symfony\Component\HttpFoundation\Response;

/**
 * D-231 (empty review), D-233 (highlight scope), D-234/D-235 (past-only rule + its three
 * exemptions), D-236 (grapheme length limits including a ZWJ emoji sequence).
 */
final class ConcertReviewValidationTest extends ConcertReviewWebTestCase
{
    public function testRatingNullAndNotesNullIsA422WithEmptyPropertyPath(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $client->request('PUT', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: self::authHeaders($auth['accessToken']), content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $violations = self::asArray($data['violations']);
        self::assertNotEmpty($violations);
        $paths = array_column($violations, 'propertyPath');
        self::assertContains('', $paths);
    }

    public function testRatingNullAndBlankNotesIsA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $client->request('PUT', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: self::authHeaders($auth['accessToken']), content: json_encode(['notes' => '   '], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testHighlightAloneWithNoRatingAndNoNotesIsStillA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken'], 'Highlight Only Band '.bin2hex(random_bytes(4)));

        $client->request(
            'PUT',
            \sprintf('/api/concerts/%d/review', self::idOf($concert)),
            server: self::authHeaders($auth['accessToken']),
            content: json_encode(['highlightTitle' => 'Just a title'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testHighlightSongOutsideThisConcertsLineupIsA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $outOfScopeSong = $this->persistSongForNewUnrelatedBand();

        $client->request(
            'PUT',
            \sprintf('/api/concerts/%d/review', self::idOf($concert)),
            server: self::authHeaders($auth['accessToken']),
            content: json_encode(['rating' => 5, 'highlightSongId' => $outOfScopeSong->getId()], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $violations = self::asArray($data['violations']);
        $paths = array_column($violations, 'propertyPath');
        self::assertContains('highlightSongId', $paths);
    }

    public function testHighlightSongInsideThisConcertsLineupIsAccepted(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $bandName = 'Lineup Band '.bin2hex(random_bytes(4));
        $concert = $this->createPastConcert($client, $auth['accessToken'], $bandName);

        $song = $this->persistSongForBand($bandName, 'The Big Single');

        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), [
            'rating' => 5,
            'highlightSongId' => $song->getId(),
            'highlightTitle' => 'The Big Single',
        ], Response::HTTP_CREATED);

        self::assertSame($song->getId(), $review['highlightSongId']);
        self::assertSame('The Big Single', $review['highlightTitle']);
    }

    public function testFreeTextHighlightSetsTitleOnlyNotSongId(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), [
            'rating' => 4,
            'highlightTitle' => 'Something else entirely',
        ], Response::HTTP_CREATED);

        self::assertNull($review['highlightSongId']);
        self::assertSame('Something else entirely', $review['highlightTitle']);
    }

    public function testFirstWriteOnAnUpcomingConcertIsA422WithReviewNotYetCode(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createUpcomingConcert($client, $auth['accessToken']);

        $client->request(
            'PUT',
            \sprintf('/api/concerts/%d/review', self::idOf($concert)),
            server: self::authHeaders($auth['accessToken']),
            content: json_encode(['rating' => 5], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) $client->getResponse()->getContent());

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $violations = self::asArray($data['violations']);
        $codes = array_column($violations, 'code');
        $paths = array_column($violations, 'propertyPath');
        self::assertContains('REVIEW_NOT_YET', $codes);
        self::assertContains('', $paths);
    }

    public function testNotesAtExactly4000GraphemesIsAccepted(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $notes = str_repeat('a', 4000);
        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['notes' => $notes], Response::HTTP_CREATED);
        self::assertSame($notes, $review['notes']);
    }

    public function testNotesOver4000GraphemesIsA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $notes = str_repeat('a', 4001);
        $client->request('PUT', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: self::authHeaders($auth['accessToken']), content: json_encode(['notes' => $notes], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * D-236: `👨‍👩‍👧‍👦` is a single ZWJ emoji sequence — 25 bytes, 7 Unicode code points, but exactly
     * ONE grapheme cluster. 4000 copies of it must be accepted (it costs 1 against the limit, not 7),
     * and it must round-trip byte for byte.
     */
    public function testZwjEmojiSequenceCountsAsOneGraphemeAndRoundTripsByteForByte(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}"; // 👨‍👩‍👧‍👦
        $notes = str_repeat($family, 4000);
        self::assertSame(4000, grapheme_strlen($notes));

        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['notes' => $notes], Response::HTTP_CREATED);
        self::assertSame($notes, $review['notes']);

        $this->getReview($client, $auth['accessToken'], self::idOf($concert));
        $reread = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame($notes, $reread['notes']);
    }

    public function testMixedScriptAndEmojiRoundTripsByteForByte(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $notes = "🎸 家族 Sigur Rós 👨\u{200D}👩\u{200D}👧\u{200D}👦 <script>alert(1)</script> {{7*7}} '); DROP TABLE concerts;--";

        $review = $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['notes' => $notes], Response::HTTP_CREATED);
        self::assertSame($notes, $review['notes']);

        $this->getReview($client, $auth['accessToken'], self::idOf($concert));
        $reread = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame($notes, $reread['notes']);
    }

    public function testHighlightTitleOver200GraphemesIsA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $client->request(
            'PUT',
            \sprintf('/api/concerts/%d/review', self::idOf($concert)),
            server: self::authHeaders($auth['accessToken']),
            content: json_encode(['rating' => 3, 'highlightTitle' => str_repeat('x', 201)], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testRatingOutOfRangeIsA422(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $client->request('PUT', \sprintf('/api/concerts/%d/review', self::idOf($concert)), server: self::authHeaders($auth['accessToken']), content: json_encode(['rating' => 6], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
