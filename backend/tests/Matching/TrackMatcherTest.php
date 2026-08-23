<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Service\Matching\Model\MatchOutcome;
use App\Service\Matching\TrackMatcher;
use App\Service\Streaming\Model\AlbumType;
use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\ProviderPlaylist;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\SongQuery;
use App\Service\Streaming\Model\TrackCandidate;
use App\Service\Streaming\StreamingProviderInterface;

/**
 * Exercises the Tier 0–7 cascade end to end (spec 12 §2) over the real resolution cache. The
 * provider itself is a small in-file fake — not `TestDoubleStreamingProvider` from
 * `tests/Support/Streaming/`, which is scripted for the streaming-port test plan and always returns
 * exactly one candidate that trivially matches the query. This fake instead returns a scripted
 * candidate LIST per call, which is what a cascade test needs.
 */
final class TrackMatcherTest extends MatchingIntegrationTestCase
{
    private ProviderTokens $tokens;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetMatchingRedis();
        $this->resetMatchingDatabase();
        $this->tokens = new ProviderTokens('access', null, new \DateTimeImmutable('+1 hour'), []);
    }

    public function testTapeIsSkippedWithoutASearchCall(): void
    {
        $band = $this->persistBand('Pink Floyd');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Walk-on Tape', isTape: true);

        $provider = new ScriptedProvider([]);
        $results = $this->matcher()->match($song, 'Pink Floyd', true, $provider, $this->tokens);

        self::assertCount(1, $results);
        self::assertSame(MatchOutcome::Skipped, $results[0]->outcome);
        self::assertSame(0, $provider->callCount);
    }

    public function testNonSongLexiconEntryIsSkippedAtASetBoundary(): void
    {
        $band = $this->persistBand('Metallica');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Intro');

        $provider = new ScriptedProvider([]);
        $results = $this->matcher()->match($song, 'Metallica', true, $provider, $this->tokens);

        self::assertSame(MatchOutcome::Skipped, $results[0]->outcome);
        self::assertSame(0, $provider->callCount);
    }

    public function testWholeTitleLexiconEntryMidSetIsStillASong(): void
    {
        // 'Jam' by Michael Jackson — the classifier's own worked example (spec 12 §5): whole-title
        // exact matching plus the set-boundary disambiguator must NOT skip a real song mid-set.
        $band = $this->persistBand('Michael Jackson');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 3, 'Jam');

        $provider = new ScriptedProvider([
            new TrackCandidate('t1', 'Jam', 'Michael Jackson', 'Dangerous', 300_000, false, false, 0.9, providerRank: 0),
        ]);
        $results = $this->matcher()->match($song, 'Michael Jackson', false, $provider, $this->tokens);

        self::assertSame(1, $provider->callCount);
        self::assertNotSame(MatchOutcome::Skipped, $results[0]->outcome);
    }

    public function testExactTitleAndArtistAutoAccepts(): void
    {
        $band = $this->persistBand('Sigur Rós');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 1, 'Sæglópur');

        $provider = new ScriptedProvider([
            new TrackCandidate('t-saeglopur', 'Sæglópur', 'Sigur Rós', 'Takk...', 360_000, false, false, 0.9, providerRank: 0),
        ]);
        $results = $this->matcher()->match($song, 'Sigur Rós', false, $provider, $this->tokens);

        self::assertCount(1, $results);
        self::assertSame(MatchOutcome::Matched, $results[0]->outcome);
        self::assertSame('t-saeglopur', $results[0]->providerTrackId);
        self::assertGreaterThanOrEqual(0.80, $results[0]->confidence);
    }

    public function testWrongArtistIsCappedByTheArtistGate(): void
    {
        $band = $this->persistBand('Nirvana');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Smells Like Teen Spirit');

        $provider = new ScriptedProvider([
            new TrackCandidate('wrong-artist', 'Smells Like Teen Spirit', 'Some Cover Band', null, 300_000, false, false, 0.9, providerRank: 0),
        ]);
        $results = $this->matcher()->match($song, 'Nirvana', false, $provider, $this->tokens);

        self::assertLessThanOrEqual(0.45, $results[0]->confidence, 'the artist gate caps at 0.45 (D-109)');
        self::assertSame(MatchOutcome::NotFound, $results[0]->outcome);
    }

    public function testCoverIsSearchedAndScoredAgainstTheOriginalArtist(): void
    {
        $band = $this->persistBand('Pearl Jam');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Sonic Reducer', coverOfName: 'Dead Boys');

        $provider = new ScriptedProvider([
            new TrackCandidate('original', 'Sonic Reducer', 'Dead Boys', null, 150_000, false, false, 0.9, providerRank: 0),
        ]);
        $results = $this->matcher()->match($song, 'Pearl Jam', false, $provider, $this->tokens);

        self::assertSame('Dead Boys', $provider->lastQuery?->bandName, 'D-113: searched by the original artist, not the performing band');
        self::assertTrue($results[0]->isCover);
        self::assertSame('Dead Boys', $results[0]->coverArtist);
        self::assertSame(MatchOutcome::Matched, $results[0]->outcome);
    }

    public function testMedleySplitsIntoOneResultPerSegment(): void
    {
        $band = $this->persistBand('Led Zeppelin');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Rock and Roll / Whole Lotta Love');

        $provider = new ScriptedProvider([
            new TrackCandidate('rar', 'Rock and Roll', 'Led Zeppelin', null, 220_000, false, false, 0.9, providerRank: 0),
            new TrackCandidate('wll', 'Whole Lotta Love', 'Led Zeppelin', null, 330_000, false, false, 0.9, providerRank: 0),
        ]);
        $results = $this->matcher()->match($song, 'Led Zeppelin', false, $provider, $this->tokens);

        self::assertCount(2, $results);
        self::assertSame(2, $provider->callCount, 'one search call per segment');
    }

    public function testALiveOnlyCandidateSetStillMatchesAtFullConfidenceAndIsFlagged(): void
    {
        $band = $this->persistBand('Grateful Dead');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Dark Star');

        $provider = new ScriptedProvider([
            new TrackCandidate('live-1', 'Dark Star (Live)', 'Grateful Dead', 'Live/Dead', 900_000, true, false, 0.9, providerRank: 0, albumType: AlbumType::LiveAlbum),
        ]);
        $results = $this->matcher()->match($song, 'Grateful Dead', false, $provider, $this->tokens);

        self::assertSame(MatchOutcome::Matched, $results[0]->outcome);
        self::assertTrue($results[0]->liveVersionOnly);
    }

    public function testZeroCandidatesIsNotFoundAndCached(): void
    {
        $band = $this->persistBand('Obscure Band');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'An Extremely Rare B-Side');

        $provider = new ScriptedProvider([]);
        $results = $this->matcher()->match($song, 'Obscure Band', false, $provider, $this->tokens);

        self::assertSame(MatchOutcome::NotFound, $results[0]->outcome);
        self::assertNull($results[0]->providerTrackId);

        // A second attempt hits the cache and makes no further search call (D-120/Tier 1).
        $provider2 = new ScriptedProvider([]);
        $this->matcher()->match($song, 'Obscure Band', false, $provider2, $this->tokens);
        self::assertSame(0, $provider2->callCount);
    }

    public function testASecondSongMakesNoSearchCallOnACacheHit(): void
    {
        $band = $this->persistBand('R.E.M.');
        $setlist = $this->persistSetlist($band);
        $song = $this->persistSong($setlist, 0, 'Losing My Religion');

        $provider = new ScriptedProvider([
            new TrackCandidate('t1', 'Losing My Religion', 'R.E.M.', null, 268_000, false, false, 0.9, providerRank: 0),
        ]);
        $this->matcher()->match($song, 'R.E.M.', false, $provider, $this->tokens);
        self::assertSame(1, $provider->callCount);

        $second = $this->persistSong($setlist, 1, 'Losing My Religion'); // Same title, re-encountered.
        $this->matcher()->match($second, 'R.E.M.', false, $provider, $this->tokens);
        self::assertSame(1, $provider->callCount, 'a resolution-cache hit spends zero further provider calls');
    }

    private function matcher(): TrackMatcher
    {
        $matcher = self::getContainer()->get(TrackMatcher::class);

        return $matcher;
    }
}

/**
 * A scriptable `StreamingProviderInterface` fake for cascade testing — every `searchTrack()` call
 * dequeues candidates from `$responses` (or repeats `$candidates` when `$responses` is empty and a
 * flat candidate list was supplied instead, for the common single-search-per-test case).
 */
final class ScriptedProvider implements StreamingProviderInterface
{
    public int $callCount = 0;
    public ?SongQuery $lastQuery = null;

    /** @param list<TrackCandidate> $candidates returned for EVERY call (the common case) */
    public function __construct(private readonly array $candidates)
    {
    }

    public function key(): string
    {
        return 'scripted';
    }

    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        throw new \LogicException('not used by TrackMatcherTest');
    }

    public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): ProviderTokens
    {
        throw new \LogicException('not used by TrackMatcherTest');
    }

    public function refreshToken(ProviderTokens $tokens): ProviderTokens
    {
        throw new \LogicException('not used by TrackMatcherTest');
    }

    public function searchTrack(SongQuery $query, ProviderTokens $tokens): array
    {
        ++$this->callCount;
        $this->lastQuery = $query;

        return $this->candidates;
    }

    public function createPlaylist(PlaylistDraft $draft, ProviderTokens $tokens): ProviderPlaylist
    {
        throw new \LogicException('not used by TrackMatcherTest');
    }

    public function addTracks(string $playlistId, array $trackIds, ProviderTokens $tokens): void
    {
        throw new \LogicException('not used by TrackMatcherTest');
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
