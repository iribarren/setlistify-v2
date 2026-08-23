<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Streaming\Spotify;

use App\Service\Streaming\Exception\TokenExpiredException;
use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Spotify\SpotifyErrorMapper;
use App\Service\Streaming\Spotify\SpotifyHttpClient;
use App\Service\Streaming\Spotify\SpotifyProvider;
use App\Service\Streaming\Spotify\SpotifyQueryBuilder;
use App\Service\Streaming\Spotify\SpotifyScopes;
use App\Service\Streaming\Spotify\SpotifyTrackMapper;
use App\Service\Streaming\StreamingProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * US-1, US-10, US-11: drives the adapter entirely through `StreamingProviderInterface` (AC-11.7)
 * against recorded fixtures (AC-12.2) — no network call to a real provider (D-2).
 */
final class SpotifyProviderTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/spotify/';

    public function testAuthorizationUrlCarriesStatePkceAndScopes(): void
    {
        $provider = $this->makeProvider(new MockHttpClient());

        $url = $provider->authorizationUrl('state-123', 'https://backend.test/callback', 'challenge-abc');

        self::assertStringContainsString('state=state-123', $url);
        self::assertStringContainsString('code_challenge=challenge-abc', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertStringContainsString(rawurlencode(SpotifyScopes::asSpaceSeparatedString()), str_replace('+', '%20', $url));
    }

    public function testExchangeCodeReturnsTokensWithIdentity(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(self::fixture('token-exchange-success.json'), ['http_code' => 200]),
            new MockResponse(self::fixture('me-identity.json'), ['http_code' => 200]),
        ]);

        $provider = $this->makeProvider($mock);
        $tokens = $provider->exchangeCode('auth-code', 'https://backend.test/callback', 'verifier-abc');

        self::assertSame('scrubbed-access-token-0001', $tokens->accessToken);
        self::assertSame('scrubbed-refresh-token-0001', $tokens->refreshToken);
        self::assertSame(['user-read-private', 'playlist-modify-private'], $tokens->scopes);
        self::assertSame('scrubbed-user-id-0001', $tokens->providerAccountId);
        self::assertSame('Scrubbed Test User', $tokens->providerDisplayName);
    }

    public function testRefreshKeepsExistingRefreshTokenWhenResponseOmitsOne(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(self::fixture('token-refresh-success-no-new-refresh-token.json'), ['http_code' => 200]),
        ]);

        $provider = $this->makeProvider($mock);
        $original = new ProviderTokens('old-access', 'old-refresh', new \DateTimeImmutable('-1 minute'), ['user-read-private']);

        $refreshed = $provider->refreshToken($original);

        self::assertSame('scrubbed-access-token-0002', $refreshed->accessToken);
        self::assertSame('old-refresh', $refreshed->refreshToken, 'AC-4.4: omitted refresh_token keeps the existing one.');
    }

    public function testRefreshWithInvalidGrantThrowsTokenExpired(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(self::fixture('error-invalid-grant.json'), ['http_code' => 400]),
        ]);

        $provider = $this->makeProvider($mock);
        $tokens = new ProviderTokens('old-access', 'revoked-refresh', new \DateTimeImmutable('-1 minute'), []);

        $this->expectException(TokenExpiredException::class);
        $provider->refreshToken($tokens);
    }

    public function testSearchTrackReturnsCandidatesOrderedByDescendingConfidence(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(self::fixture('search-with-results.json'), ['http_code' => 200]),
        ]);

        $provider = $this->makeProvider($mock);
        $tokens = new ProviderTokens('access', null, new \DateTimeImmutable('+1 hour'), []);

        $candidates = $provider->searchTrack(new SongQuery('Master of Puppets', 'Metallica'), $tokens);

        self::assertCount(2, $candidates);
        self::assertGreaterThanOrEqual($candidates[1]->confidence, $candidates[0]->confidence);
        self::assertFalse($candidates[0]->isLive);
        self::assertTrue($candidates[1]->isLive, 'AC-11.3: "Live" in the title is detected.');
    }

    public function testSearchWithZeroResultsReturnsEmptyArrayNotException(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(self::fixture('search-zero-results.json'), ['http_code' => 200]),
        ]);

        $provider = $this->makeProvider($mock);
        $tokens = new ProviderTokens('access', null, new \DateTimeImmutable('+1 hour'), []);

        self::assertSame([], $provider->searchTrack(new SongQuery('Nonexistent Song', 'Nobody'), $tokens));
    }

    public function testCreatePlaylistIsAlwaysPrivate(): void
    {
        $capturedBody = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            $capturedBody = $options['body'] ?? null;
            if (str_contains($url, '/me')) {
                return new MockResponse(self::fixture('me-identity.json'), ['http_code' => 200]);
            }

            return new MockResponse(self::fixture('create-playlist-success.json'), ['http_code' => 200]);
        });

        $provider = $this->makeProvider($mock);
        $tokens = new ProviderTokens('access', null, new \DateTimeImmutable('+1 hour'), []);

        $playlist = $provider->createPlaylist(new PlaylistDraft('Test Concert Setlist'), $tokens);

        self::assertSame('scrubbed-playlist-id-0001', $playlist->providerPlaylistId);
        self::assertSame('https://open.spotify.com/playlist/scrubbed-playlist-id-0001', $playlist->externalUrl);
        self::assertIsString($capturedBody);
        self::assertStringContainsString('"public":false', (string) $capturedBody, 'D-87: private by default, always.');
    }

    public function testAddTracksChunksToTheProviderBatchLimit(): void
    {
        $requestCount = 0;
        $mock = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('{"snapshot_id":"abc"}', ['http_code' => 200]);
        });

        $provider = $this->makeProvider($mock);
        $tokens = new ProviderTokens('access', null, new \DateTimeImmutable('+1 hour'), []);

        $trackIds = array_map(static fn (int $i): string => 'track-'.$i, range(1, 250));
        $provider->addTracks('playlist-1', $trackIds, $tokens);

        self::assertSame(3, $requestCount, 'AC-11.5: 250 tracks at a 100-per-request batch limit is 3 calls.');
    }

    public function testPlaybackSurfaceUrls(): void
    {
        $provider = $this->makeProvider(new MockHttpClient());

        self::assertSame('https://open.spotify.com/embed/playlist/p1', $provider->playlistEmbedUrl('p1'));
        self::assertSame('https://open.spotify.com/playlist/p1', $provider->playlistDeepLink('p1'));
    }

    public function testImplementsThePortInterface(): void
    {
        self::assertInstanceOf(StreamingProviderInterface::class, $this->makeProvider(new MockHttpClient()));
    }

    private function makeProvider(MockHttpClient $mock): SpotifyProvider
    {
        $httpClient = new SpotifyHttpClient($mock, $mock, new SpotifyErrorMapper(), new NullLogger());

        return new SpotifyProvider(
            $httpClient,
            new SpotifyErrorMapper(),
            new SpotifyTrackMapper(),
            new SpotifyQueryBuilder(),
            clientId: 'test-client-id',
            clientSecret: 'test-client-secret',
            accountsBaseUrl: 'https://accounts.spotify.test',
        );
    }

    private static function fixture(string $name): string
    {
        $contents = file_get_contents(self::FIXTURES.$name);
        self::assertNotFalse($contents);

        return $contents;
    }
}
