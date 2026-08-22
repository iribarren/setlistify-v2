<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Streaming;

use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\StreamingProviderLocator;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-9.5's literal proof: `TestDoubleStreamingProvider` is registered ONLY by carrying the
 * `app.streaming_provider` tag (`config/services.yaml`'s `when@test:` block) — nothing in `src/`
 * was edited to accept it. This test resolves it purely through the real, container-wired
 * `StreamingProviderLocator` and drives a link -> search -> create-playlist path using only
 * `StreamingProviderInterface`, proving "one directory, one registration" rather than asserting it.
 */
final class TestDoubleProviderIsDiscoverableTest extends KernelTestCase
{
    public function testTheTestDoubleIsDiscoverableAndUsableThroughOnlyThePortInterface(): void
    {
        self::bootKernel();
        $locator = static::getContainer()->get(StreamingProviderLocator::class);

        self::assertContains(TestDoubleStreamingProvider::KEY, $locator->keys());

        $provider = $locator->get(TestDoubleStreamingProvider::KEY);

        $authUrl = $provider->authorizationUrl('state-1', 'https://backend.test/callback', 'challenge-1');
        self::assertStringContainsString('state=state-1', $authUrl);

        $tokens = $provider->exchangeCode('code-1', 'https://backend.test/callback', 'verifier-1');
        self::assertNotSame('', $tokens->accessToken);

        $candidates = $provider->searchTrack(new SongQuery('Some Song', 'Some Band'), $tokens);
        self::assertNotSame([], $candidates);

        $playlist = $provider->createPlaylist(new PlaylistDraft('Test Playlist'), $tokens);
        self::assertNotSame('', $playlist->providerPlaylistId);

        $provider->addTracks($playlist->providerPlaylistId, [$candidates[0]->providerTrackId], $tokens);
    }
}
