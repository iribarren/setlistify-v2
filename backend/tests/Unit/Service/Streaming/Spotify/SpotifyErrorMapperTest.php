<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Streaming\Spotify;

use App\Service\Streaming\Exception\NotFoundException;
use App\Service\Streaming\Exception\ProviderUnavailableException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Exception\RegionRestrictedException;
use App\Service\Streaming\Exception\TokenExpiredException;
use App\Service\Streaming\Spotify\SpotifyErrorMapper;
use PHPUnit\Framework\TestCase;

/**
 * AC-10.3: every taxonomy case driven from a recorded (scrubbed) fixture — 401 expired, 429 with
 * `Retry-After`, 404, 403 region/market, 5xx, and the token endpoint's `invalid_grant` shape.
 * AC-10.5: anything unclassified becomes `ProviderUnavailableException`.
 */
final class SpotifyErrorMapperTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/spotify/';

    public function test401MapsToTokenExpired(): void
    {
        $exception = (new SpotifyErrorMapper())->map(401, self::fixture('error-401-expired.json'), []);
        self::assertInstanceOf(TokenExpiredException::class, $exception);
    }

    public function test429MapsToRateLimitedWithRetryAfterAsPlainInteger(): void
    {
        $exception = (new SpotifyErrorMapper())->map(429, self::fixture('error-429-rate-limited.json'), ['retry-after' => ['12']]);

        self::assertInstanceOf(RateLimitedException::class, $exception);
        self::assertSame(12, $exception->retryAfterSeconds);
    }

    public function test404MapsToNotFound(): void
    {
        $exception = (new SpotifyErrorMapper())->map(404, self::fixture('error-404-not-found.json'), []);
        self::assertInstanceOf(NotFoundException::class, $exception);
    }

    public function test403MapsToRegionRestricted(): void
    {
        $exception = (new SpotifyErrorMapper())->map(403, self::fixture('error-403-region-restricted.json'), []);
        self::assertInstanceOf(RegionRestrictedException::class, $exception);
    }

    public function test5xxMapsToProviderUnavailable(): void
    {
        $exception = (new SpotifyErrorMapper())->map(500, self::fixture('error-500-upstream.json'), []);
        self::assertInstanceOf(ProviderUnavailableException::class, $exception);
    }

    public function testUnclassified4xxMapsToProviderUnavailable(): void
    {
        // AC-10.5: e.g. a 400 that isn't the token endpoint's invalid_grant shape.
        $exception = (new SpotifyErrorMapper())->map(400, '{"error":{"status":400,"message":"bad request"}}', []);
        self::assertInstanceOf(ProviderUnavailableException::class, $exception);
    }

    public function testTransportFailureMapsToProviderUnavailable(): void
    {
        self::assertInstanceOf(ProviderUnavailableException::class, (new SpotifyErrorMapper())->mapTransportFailure());
    }

    public function testTokenEndpointInvalidGrantMapsToTokenExpired(): void
    {
        $exception = (new SpotifyErrorMapper())->mapTokenEndpointError(400, self::fixture('error-invalid-grant.json'), []);
        self::assertInstanceOf(TokenExpiredException::class, $exception);
    }

    public function testTokenEndpointOtherErrorMapsToProviderUnavailable(): void
    {
        $exception = (new SpotifyErrorMapper())->mapTokenEndpointError(400, '{"error":"invalid_client"}', []);
        self::assertInstanceOf(ProviderUnavailableException::class, $exception);
    }

    private static function fixture(string $name): string
    {
        $contents = file_get_contents(self::FIXTURES.$name);
        self::assertNotFalse($contents);

        return $contents;
    }
}
