<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

/**
 * AC-6.4 — "the single most important test in the feature": two identical reads produce exactly
 * one outbound HTTP request. Runs against real Redis and PostgreSQL (AC-13.5); only the HTTP
 * transport is mocked (AC-13.1).
 */
final class ZeroOutboundCallOnRepeatReadTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testRepeatedArtistSearchMakesExactlyOneOutboundCall(): void
    {
        $gateway = $this->makeGateway([self::fixtureResponse('artist-search-single-match.json')]);

        $first = $gateway->searchArtist('Radiohead');
        $second = $gateway->searchArtist('Radiohead');

        self::assertSame(1, $this->outboundRequestCount, 'A second identical search must be served entirely from cache.');
        self::assertSame('live', $first->source);
        self::assertSame('cache', $second->source);
        self::assertSame($first->payload, $second->payload);
    }

    public function testRepeatedSetlistDetailReadMakesExactlyOneOutboundCall(): void
    {
        $gateway = $this->makeGateway([self::fixtureResponse('setlist-detail-covers-tape-encores.json')]);

        $first = $gateway->fetchSetlistDetail('63de4613');
        $second = $gateway->fetchSetlistDetail('63de4613');

        self::assertSame(1, $this->outboundRequestCount);
        self::assertSame('live', $first->source);
        self::assertSame('cache', $second->source);
    }

    public function testDifferentCallSitesAskingTheSameQuestionShareOneCacheEntry(): void
    {
        // AC-6.6: cache keys are derived from the canonical request, not a caller-supplied string —
        // two gateway instances (simulating two different call sites/requests) hitting the same
        // Redis+Postgres still only cost one outbound call between them.
        $gatewayA = $this->makeGateway([self::fixtureResponse('artist-search-empty.json')]);
        $gatewayA->searchArtist('Some Obscure Band');

        $gatewayB = $this->makeGateway([]);
        $second = $gatewayB->searchArtist('Some Obscure Band');

        self::assertSame(0, $this->outboundRequestCount, 'A different call site asking the same question must not make its own outbound call.');
        self::assertSame('cache', $second->source);
    }
}
