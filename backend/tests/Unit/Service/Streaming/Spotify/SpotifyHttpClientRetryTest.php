<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Streaming\Spotify;

use App\Service\Streaming\Exception\NotFoundException;
use App\Service\Streaming\Exception\ProviderUnavailableException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Spotify\SpotifyErrorMapper;
use App\Service\Streaming\Spotify\SpotifyHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** AC-10.6: bounded, jittered retries on transient failures only — never on a 404. */
final class SpotifyHttpClientRetryTest extends TestCase
{
    public function testRateLimitedIsRetriedThenFails(): void
    {
        $attempts = 0;
        $mock = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{"error":{"status":429,"message":"rate limited"}}', ['http_code' => 429, 'response_headers' => ['retry-after' => '0']]);
        });

        $client = $this->makeClient($mock);

        $this->expectException(RateLimitedException::class);

        try {
            $client->get('search', '/search', [], 'token');
        } finally {
            self::assertSame(3, $attempts, '1 initial attempt + 2 retries, never unbounded (AC-10.6).');
        }
    }

    public function test5xxIsRetriedThenBecomesProviderUnavailable(): void
    {
        $attempts = 0;
        $mock = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{"error":{"status":500,"message":"upstream"}}', ['http_code' => 500]);
        });

        $client = $this->makeClient($mock);

        $this->expectException(ProviderUnavailableException::class);

        try {
            $client->get('search', '/search', [], 'token');
        } finally {
            self::assertSame(3, $attempts);
        }
    }

    public function test404IsNeverRetried(): void
    {
        $attempts = 0;
        $mock = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{"error":{"status":404,"message":"not found"}}', ['http_code' => 404]);
        });

        $client = $this->makeClient($mock);

        $this->expectException(NotFoundException::class);

        try {
            $client->get('search', '/search', [], 'token');
        } finally {
            self::assertSame(1, $attempts, 'A 404 must never be retried.');
        }
    }

    private function makeClient(MockHttpClient $mock): SpotifyHttpClient
    {
        return new SpotifyHttpClient($mock, $mock, new SpotifyErrorMapper(), new NullLogger());
    }
}
