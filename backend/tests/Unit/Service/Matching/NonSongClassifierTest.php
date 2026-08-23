<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use App\Entity\Setlist;
use App\Entity\Song;
use App\Service\Matching\NonSongClassifier;
use App\Service\Matching\SongNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classifier's required precision is **1.00**: no real song may ever be classified as a non-song.
 * Recall may be imperfect — a missed artifact becomes a mildly noisy `not_found` line, which is not
 * wrong. Every test here that asserts a real song is NOT classified is guarding that asymmetry, which
 * is the entire argument for a curated list over a classifier (D-116).
 */
#[CoversClass(NonSongClassifier::class)]
final class NonSongClassifierTest extends TestCase
{
    private NonSongClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new NonSongClassifier(
            new SongNormalizer(),
            ['drum solo', 'bass solo', 'encore break', 'tuning', 'solo de bateria'],
            ['intro', 'outro', 'interlude', 'jam'],
        );
    }

    private static function song(string $title, bool $isTape = false, int $position = 5): Song
    {
        return new Song(
            self::createStub(Setlist::class),
            $position,
            null,
            $title,
            null,
            null,
            null,
            null,
            $isTape,
        );
    }

    /**
     * Signal 1, checked first: setlist.fm's own flag, preserved by prompt 09 precisely so this class
     * could decide what to do with it. Free, structural and language-independent.
     */
    public function testTapeIsAlwaysANonSongWhateverItIsCalled(): void
    {
        self::assertTrue($this->classifier->isNonSong(self::song('The Ecstasy of Gold', isTape: true), false));
    }

    #[DataProvider('unambiguousArtifacts')]
    public function testUnambiguousArtifactsClassifyAnywhereInTheSet(string $title): void
    {
        self::assertTrue($this->classifier->isNonSong(self::song($title), false));
    }

    /** @return iterable<string, array{string}> */
    public static function unambiguousArtifacts(): iterable
    {
        yield 'drum solo' => ['Drum Solo'];
        yield 'bass solo' => ['Bass Solo'];
        yield 'encore break' => ['Encore Break'];
        yield 'tuning' => ['Tuning'];
        yield 'spanish drum solo' => ['Solo de batería'];
    }

    /**
     * The property that makes the lexicon safe: whole-title exact, NEVER substring. `Intro` by The xx
     * and `Jam` by Michael Jackson are real released songs, and a substring match would destroy both.
     */
    #[DataProvider('realSongsContainingArtifactWords')]
    public function testLexiconNeverMatchesAsASubstring(string $title): void
    {
        self::assertFalse(
            $this->classifier->isNonSong(self::song($title), true),
            'A real song containing an artifact word must never be classified as a non-song.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function realSongsContainingArtifactWords(): iterable
    {
        yield 'drum solo as substring' => ['Drum Solo Blues'];
        yield 'intro as substring' => ['Introduction to the Blues'];
        yield 'jam as substring' => ['Jammin\''];
        yield 'tuning as substring' => ['Tuning In'];
    }

    /** The position disambiguator: `Intro` at a set boundary is an artifact; mid-set it is a song. */
    public function testPositionSensitiveTermIsAnArtifactOnlyAtASetBoundary(): void
    {
        self::assertTrue($this->classifier->isNonSong(self::song('Intro', position: 0), true));
        self::assertFalse($this->classifier->isNonSong(self::song('Intro', position: 7), false));
    }

    public function testPositionSensitiveTermMidSetIsTreatedAsARealSong(): void
    {
        // `Jam` — Michael Jackson. Mid-set, this must reach the provider.
        self::assertFalse($this->classifier->isNonSong(self::song('Jam', position: 6), false));
    }

    /**
     * Signal 3 is ADVISORY. It records suspicion; it never promotes a miss into a skip. Upgrading a
     * `not_found` into "that wasn't a song" on a heuristic would be the system covering its own
     * failures — the exact opposite of the honesty the product sells.
     */
    public function testSuspicionIsAdvisoryAndNeverClassifiesAsNonSong(): void
    {
        $obscure = self::song('Vaka', position: 4);

        self::assertTrue($this->classifier->isSuspicious($obscure, false));
        self::assertFalse(
            $this->classifier->isNonSong($obscure, false),
            'Suspicion must never be sufficient to skip a song.',
        );
    }

    public function testNothingIsSuspiciousOnceACandidateClearsReject(): void
    {
        self::assertFalse($this->classifier->isSuspicious(self::song('Vaka'), true));
    }

    public function testALongTitleWithNoCandidatesIsNotSuspicious(): void
    {
        self::assertFalse($this->classifier->isSuspicious(self::song('Everything In Its Right Place'), false));
    }
}
