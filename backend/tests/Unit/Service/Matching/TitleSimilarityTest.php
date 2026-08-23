<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use App\Service\Matching\Similarity\TitleSimilarity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TitleSimilarity::class)]
final class TitleSimilarityTest extends TestCase
{
    public function testIdenticalCoresScoreOne(): void
    {
        self::assertSame(1.0, TitleSimilarity::trigramDice('kid a', 'kid a'));
        self::assertSame(1.0, TitleSimilarity::weightedJaccard(['kid', 'a'], ['kid', 'a']));
    }

    /**
     * The explicit regression test for the `levenshtein()` flaw that motivated this metric: a
     * byte-based comparison scores `('sigur rós', 'sigur ros')` at a distance of 2 rather than 1,
     * because `ó` is two bytes. Trigrams must be built over CODE POINTS.
     */
    public function testTrigramsAreCodePointSafeNotByteBased(): void
    {
        // The same substitution position in both cases, so only the code-point-vs-byte question
        // separates them: a two-byte `ó` must cost exactly what a one-byte `x` costs.
        $accented = TitleSimilarity::trigramDice('sigur ros', 'sigur rós');
        $ascii = TitleSimilarity::trigramDice('sigur ros', 'sigur rxs');

        self::assertSame(
            $ascii,
            $accented,
            'A one-code-point diacritic substitution must cost exactly what a one-code-point ASCII substitution costs.',
        );

        // And an identical pair must still be 1.0 once the accent is present on both sides.
        self::assertSame(1.0, TitleSimilarity::trigramDice('sigur rós', 'sigur rós'));
    }

    public function testTrigramDiceIsSymmetric(): void
    {
        $forward = TitleSimilarity::trigramDice('paranoid android', 'paranoid andriod');
        $backward = TitleSimilarity::trigramDice('paranoid andriod', 'paranoid android');

        self::assertSame($forward, $backward);
    }

    public function testWeightedJaccardIsSymmetric(): void
    {
        $forward = TitleSimilarity::weightedJaccard(['the', 'end'], ['end']);
        $backward = TitleSimilarity::weightedJaccard(['end'], ['the', 'end']);

        self::assertSame($forward, $backward);
    }

    /** A typo inside a token is exactly what the trigram half exists to absorb. */
    public function testTypoScoresHighOnTrigrams(): void
    {
        self::assertGreaterThanOrEqual(0.8, TitleSimilarity::trigramDice('paranoid android', 'paranoid andriod'));
    }

    /**
     * N6's payoff: a leading article is a stop token weighted 0.25, so its absence costs a little and
     * never decides. `the end` vs `end` must stay clearly above a genuinely different title.
     */
    public function testStopTokensCostLittle(): void
    {
        $articleOnly = TitleSimilarity::weightedJaccard(['the', 'end'], ['end']);
        $realWordMissing = TitleSimilarity::weightedJaccard(['bitter', 'end'], ['end']);

        self::assertGreaterThan($realWordMissing, $articleOnly);
        self::assertGreaterThan(0.75, $articleOnly);
    }

    /** Word reordering is what the Jaccard half handles and trigrams do not. */
    public function testTokenSetIsOrderIndependent(): void
    {
        self::assertSame(1.0, TitleSimilarity::weightedJaccard(['love', 'whole', 'lotta'], ['whole', 'lotta', 'love']));
    }

    /** Padding makes word boundaries participate, so a short title is not swallowed by a longer one. */
    public function testPaddingKeepsShortTitleFromMatchingSuperstring(): void
    {
        self::assertLessThan(0.8, TitleSimilarity::trigramDice('end', 'the bitter end'));
    }

    #[DataProvider('emptyCases')]
    public function testEmptyCores(string $a, string $b, float $expected): void
    {
        self::assertSame($expected, TitleSimilarity::trigramDice($a, $b));
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function emptyCases(): iterable
    {
        yield 'both empty' => ['', '', 1.0];
        yield 'one empty' => ['', 'kid a', 0.0];
        yield 'other empty' => ['kid a', '', 0.0];
    }

    public function testBlendIsWeightedSumOfBothHalves(): void
    {
        $similarity = new TitleSimilarity(0.60, 0.40);

        $score = $similarity->score('whole lotta love', ['whole', 'lotta', 'love'], 'whole lotta love', ['whole', 'lotta', 'love']);

        self::assertSame(1.0, round($score, 10));
    }

    public function testUnrelatedTitlesScoreLow(): void
    {
        $similarity = new TitleSimilarity();

        $score = $similarity->score('saeglopur', ['saeglopur'], 'creep', ['creep']);

        self::assertLessThan(0.2, $score);
    }
}
