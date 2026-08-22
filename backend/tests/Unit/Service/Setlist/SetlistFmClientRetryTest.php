<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setlist;

use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistFmClient;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * US-9: retries are capped, apply only to transient failures, and a `Retry-After` header is
 * honoured (AC-9.2, AC-9.3). Uses the AC-13.4 429/500 fixtures.
 */
final class SetlistFmClientRetryTest extends \PHPUnit\Framework\TestCase
{
    private const string FIXTURES = __DIR__.'/../../../Fixtures/setlistfm/';

    protected function setUp(): void
    {
        // This class shares real Redis with every other test in the suite (AC-13.5) but isn't a
        // KernelTestCase, so nothing else clears the budget/breaker keys for it — without this, a
        // circuit breaker opened by SetlistFmBudgetTest's own breaker test (same 'setlistfm:
        // breaker:*' keys) would leak in and make these retry-count assertions flaky.
        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $keys = $redis->keys('setlistfm:*');
        if ([] !== $keys) {
            $redis->del($keys);
        }
    }

    public function test429WithRetryAfterIsRetriedAndBoundedByTheCap(): void
    {
        $attempts = 0;
        $responses = [
            new MockResponse(self::fixture('error-429.json'), ['http_code' => 429, 'response_headers' => ['retry-after' => '0']]),
            new MockResponse(self::fixture('error-429.json'), ['http_code' => 429, 'response_headers' => ['retry-after' => '0']]),
            new MockResponse(self::fixture('error-429.json'), ['http_code' => 429, 'response_headers' => ['retry-after' => '0']]),
        ];
        $mock = new MockHttpClient(function () use (&$responses, &$attempts): MockResponse {
            ++$attempts;

            return array_shift($responses);
        });

        $client = $this->makeClient($mock);
        $result = $client->request('artist.search', '/search/artists', ['artistName' => 'x']);

        self::assertTrue($result->degraded);
        self::assertSame('upstream_unavailable', $result->degradedReason);
        // 1 initial attempt + 2 retries (MAX_RETRIES=2) = 3 total attempts, never unbounded.
        self::assertSame(3, $attempts);
    }

    public function test404IsNeverRetried(): void
    {
        $attempts = 0;
        $mock = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{}', ['http_code' => 404]);
        });

        $client = $this->makeClient($mock);
        $result = $client->request('setlist.get', '/setlist/x');

        self::assertTrue($result->notFound);
        self::assertSame(1, $attempts, 'A 404 must never be retried (AC-9.2).');
    }

    public function test500IsRetriedThenDegrades(): void
    {
        $attempts = 0;
        $mock = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse(self::fixture('error-500.json'), ['http_code' => 500]);
        });

        $client = $this->makeClient($mock);
        $result = $client->request('setlist.get', '/setlist/x');

        self::assertTrue($result->degraded);
        self::assertSame(3, $attempts);
    }

    public function testOtherClientErrorsAreNotRetried(): void
    {
        $attempts = 0;
        $mock = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{}', ['http_code' => 400]);
        });

        $client = $this->makeClient($mock);
        $result = $client->request('artist.search', '/search/artists');

        self::assertSame(1, $attempts, 'A non-429 4xx must never be retried (AC-9.2).');
        self::assertTrue($result->degraded);
    }

    private function makeClient(MockHttpClient $httpClient): SetlistFmClient
    {
        // This test targets SetlistFmClient's retry/backoff/status-handling logic — the budget
        // gate itself is covered separately (SetlistFmBudgetTest). A real Redis connection (the
        // same host every other test in this suite uses, AC-13.5) with a generous budget just
        // needs to stay out of the way here.
        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $budget = new SetlistFmBudget($redis, new MockClock(), new NullLogger(), ratePerSecond: 1000, dailyBudget: 1_000_000, tokenWaitSeconds: 0.01);

        return new SetlistFmClient($httpClient, $budget, new NullLogger(), 'unused');
    }

    private static function fixture(string $name): string
    {
        $contents = file_get_contents(self::FIXTURES.$name);
        self::assertNotFalse($contents);

        return $contents;
    }
}
