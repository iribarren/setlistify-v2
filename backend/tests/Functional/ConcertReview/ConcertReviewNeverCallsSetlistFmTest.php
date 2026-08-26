<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use App\Tests\Support\Setlist\CountingSetlistFmHttpClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-232, AC-5.6: building the highlight picker (and any review read/write) performs ZERO
 * `SetlistGateway`/setlist.fm calls — it only reads `Setlist`/`Song` rows already persisted. Proven
 * with a spy on the real HTTP transport (`App\Tests\Support\Setlist\CountingSetlistFmHttpClient`),
 * not a grep — the spec's own instruction, since "no source file mentions the gateway" wouldn't
 * catch a call routed some other way.
 */
final class ConcertReviewNeverCallsSetlistFmTest extends ConcertReviewWebTestCase
{
    private function httpSpy(): CountingSetlistFmHttpClient
    {
        $spy = static::getContainer()->get(CountingSetlistFmHttpClient::class);
        self::assertInstanceOf(CountingSetlistFmHttpClient::class, $spy);

        return $spy;
    }

    public function testWritingAndReadingAReviewWithAStructuredHighlightNeverCallsSetlistFm(): void
    {
        $client = $this->createAuthClient();
        $this->httpSpy()->reset();

        $auth = $this->registerAndLogin($client);
        $bandName = 'No Gateway Band '.bin2hex(random_bytes(4));
        $concert = $this->createPastConcert($client, $auth['accessToken'], $bandName);
        $song = $this->persistSongForBand($bandName, 'Never Fetched Live');

        $this->httpSpy()->reset();

        $this->putReview($client, $auth['accessToken'], self::idOf($concert), [
            'rating' => 5,
            'highlightSongId' => $song->getId(),
            'highlightTitle' => 'Never Fetched Live',
        ], Response::HTTP_CREATED);

        $this->getReview($client, $auth['accessToken'], self::idOf($concert));
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->httpSpy()->getRequestCount(), 'A review write/read must never call out to setlist.fm (D-232, AC-5.6).');
    }
}
