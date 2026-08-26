<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-241, AC-6.1-AC-6.6: `ConcertOutput.reviewSummary` — correct when present, absent-or-null when
 * there is no review (this app's JSON-LD serialization omits null properties entirely rather than
 * rendering `null` — the same is true of the pre-existing `ticketPrice`/`doorsTime`/`startTime`
 * fields, so this matches established behaviour rather than introducing something new), never
 * carries the notes body, and costs the collection endpoint at most one extra query for the WHOLE
 * page (no N+1). `?reviewed=true|false` filters correctly and is index-backed (same join).
 */
final class ConcertReviewListIndicatorTest extends ConcertReviewWebTestCase
{
    public function testReviewSummaryIsCorrectWhenPresentAbsentWhenNotAndNeverCarriesNotes(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $unreviewed = $this->createPastConcert($client, $auth['accessToken']);
        $reviewed = $this->createPastConcert($client, $auth['accessToken']);
        $this->putReview($client, $auth['accessToken'], self::idOf($reviewed), [
            'rating' => 4,
            'notes' => 'This must never appear in the list response.',
        ], Response::HTTP_CREATED);

        $client->request('GET', '/api/concerts', server: self::authHeaders($auth['accessToken']));
        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $members = self::membersOf($data);

        $byId = [];
        foreach ($members as $member) {
            $member = self::asArray($member);
            $byId[self::idOf($member)] = $member;
        }

        self::assertNull($byId[self::idOf($unreviewed)]['reviewSummary'] ?? null);

        $summary = self::asArray($byId[self::idOf($reviewed)]['reviewSummary']);
        self::assertSame(4, $summary['rating']);
        self::assertArrayNotHasKey('notes', $summary, 'AC-6.2: the summary must never carry the notes body.');

        // Also true for the single-item GET (US-3 item form).
        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($reviewed)), server: self::authHeaders($auth['accessToken']));
        $item = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $itemSummary = self::asArray($item['reviewSummary']);
        self::assertSame(4, $itemSummary['rating']);
        self::assertArrayNotHasKey('notes', $itemSummary);
    }

    public function testReviewedFilterIncludesOrExcludesCorrectly(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);

        $reviewed = $this->createPastConcert($client, $auth['accessToken']);
        $unreviewed = $this->createPastConcert($client, $auth['accessToken']);
        $this->putReview($client, $auth['accessToken'], self::idOf($reviewed), ['rating' => 5], Response::HTTP_CREATED);

        $client->request('GET', '/api/concerts?reviewed=true', server: self::authHeaders($auth['accessToken']));
        $trueIds = array_map(static fn (array $m): int => self::idOf($m), array_map(self::asArray(...), self::membersOf(self::decodeJsonObject((string) $client->getResponse()->getContent()))));
        self::assertContains(self::idOf($reviewed), $trueIds);
        self::assertNotContains(self::idOf($unreviewed), $trueIds);

        $client->request('GET', '/api/concerts?reviewed=false', server: self::authHeaders($auth['accessToken']));
        $falseIds = array_map(static fn (array $m): int => self::idOf($m), array_map(self::asArray(...), self::membersOf(self::decodeJsonObject((string) $client->getResponse()->getContent()))));
        self::assertContains(self::idOf($unreviewed), $falseIds);
        self::assertNotContains(self::idOf($reviewed), $falseIds);
    }

    /**
     * AC-6.5: fetching `reviewSummary` must cost exactly one extra query for the WHOLE page, never
     * one per concert. Isolated by comparing two pages of the SAME size (12 concerts) — one with NO
     * reviews, one where EVERY concert has one — so anything pre-existing that already scales with
     * page size (e.g. per-concert lineup loading, unrelated to this feature) affects both sides
     * equally and cancels out of the comparison; only what THIS feature added should show up in the
     * difference.
     */
    public function testFetchingReviewSummariesAddsAtMostOneQueryRegardlessOfHowManyConcertsAreReviewed(): void
    {
        $client = $this->createAuthClient();
        $unreviewedAuth = $this->registerAndLogin($client);
        for ($i = 0; $i < 12; ++$i) {
            $this->createConcert($client, $unreviewedAuth['accessToken'], self::minimalConcertPayload(\sprintf('2020-01-%02d', $i + 1)));
        }

        $client->request('GET', '/api/concerts?itemsPerPage=12', server: self::authHeaders($unreviewedAuth['accessToken']));
        self::assertResponseIsSuccessful();
        $noReviewsQueryCount = $this->queryCount();

        $reviewedAuth = $this->registerAndLogin($client);
        for ($i = 0; $i < 12; ++$i) {
            $concert = $this->createConcert($client, $reviewedAuth['accessToken'], self::minimalConcertPayload(\sprintf('2020-02-%02d', $i + 1)));
            $this->putReview($client, $reviewedAuth['accessToken'], self::idOf($concert), ['rating' => ($i % 5) + 1], Response::HTTP_CREATED);
        }

        $client->request('GET', '/api/concerts?itemsPerPage=12', server: self::authHeaders($reviewedAuth['accessToken']));
        self::assertResponseIsSuccessful();
        $allReviewedQueryCount = $this->queryCount();

        self::assertLessThanOrEqual(
            $noReviewsQueryCount + 1,
            $allReviewedQueryCount,
            'Fetching reviewSummary for a full page of reviewed concerts must add at most ONE query compared to an all-unreviewed page of the same size (AC-6.5, no N+1).',
        );
    }

    private function queryCount(): int
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        $data = $holder->getData();
        $queries = $data['default'] ?? [];

        return \count($queries);
    }
}
