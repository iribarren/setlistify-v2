<?php

declare(strict_types=1);

namespace App\Tests\Support\Matching;

use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\ProviderPlaylist;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Model\TrackCandidate;
use App\Service\Streaming\StreamingProviderInterface;

/**
 * A scriptable `StreamingProviderInterface` fake shared by `App\Tests\Matching\*` (mirrors the
 * in-file `ScriptedProvider` in `TrackMatcherTest`, promoted to a reusable class here so
 * `MatchingQualityHarnessTest` does not duplicate it). Every `searchTrack()` call returns the same
 * `$candidates` list — good enough for the harness's structural fixtures, which only need to prove
 * whether the cascade calls the provider at all (Tier 0 skip) or reaches Tier 2 with an outcome that
 * is not `Skipped`, never a specific matched track id.
 */
final class ScriptedMatchingProvider implements StreamingProviderInterface
{
    public int $callCount = 0;

    /** @var list<SongQuery> */
    public array $queries = [];

    /** @param list<TrackCandidate> $candidates returned for EVERY call */
    public function __construct(private readonly array $candidates = [])
    {
    }

    public function key(): string
    {
        return 'scripted';
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        throw new \LogicException('not used by the matching quality harness');
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): ProviderTokens
    {
        throw new \LogicException('not used by the matching quality harness');
    }

    public function refreshToken(ProviderTokens $tokens): ProviderTokens
    {
        throw new \LogicException('not used by the matching quality harness');
    }

    public function searchTrack(SongQuery $query, ProviderTokens $tokens): array
    {
        ++$this->callCount;
        $this->queries[] = $query;

        return $this->candidates;
    }

    public function createPlaylist(PlaylistDraft $draft, ProviderTokens $tokens): ProviderPlaylist
    {
        throw new \LogicException('not used by the matching quality harness');
    }

    public function addTracks(string $playlistId, array $trackIds, ProviderTokens $tokens): void
    {
        throw new \LogicException('not used by the matching quality harness');
    }

    public function playlistEmbedUrl(string $playlistId): ?string
    {
        return null;
    }

    public function playlistDeepLink(string $playlistId): string
    {
        return 'scripted://'.$playlistId;
    }
}
