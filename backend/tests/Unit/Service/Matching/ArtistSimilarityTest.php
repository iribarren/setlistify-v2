<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use App\Service\Matching\Similarity\ArtistSimilarity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArtistSimilarity::class)]
final class ArtistSimilarityTest extends TestCase
{
    private ArtistSimilarity $similarity;

    protected function setUp(): void
    {
        $this->similarity = new ArtistSimilarity();
    }

    public function testExactMatchAfterBandNormalization(): void
    {
        self::assertSame(1.0, $this->similarity->score('Radiohead', 'Radiohead'));
    }

    /** The cases `BandResolver::normalize()` was written for — reused verbatim here (D-106). */
    public function testDiacriticsAndLeadingArticlesAreHandledByTheSharedNormalizer(): void
    {
        self::assertSame(1.0, $this->similarity->score('Sigur Rós', 'Sigur Ros'));
        self::assertSame(1.0, $this->similarity->score('The Rolling Stones', 'Rolling Stones'));
    }

    public function testExpectedArtistAmongOtherCreditsScoresLowerThanPrimary(): void
    {
        $score = $this->similarity->score('David Bowie', 'Queen', ['David Bowie']);

        self::assertSame(0.90, $score);
    }

    public function testPrefixSupersetScores(): void
    {
        self::assertSame(
            ArtistSimilarity::PREFIX_SUPERSET_SCORE,
            $this->similarity->score('Bruce Springsteen', 'Bruce Springsteen & The E Street Band'),
        );
    }

    /** Containment must respect token boundaries, or `The B` would match `The Beatles`. */
    public function testPartialWordIsNotAPrefixSuperset(): void
    {
        self::assertSame(0.0, $this->similarity->score('Beat', 'Beatles'));
    }

    public function testUnrelatedArtistScoresZeroAndTherebyTripsTheGate(): void
    {
        self::assertSame(0.0, $this->similarity->score('Sigur Rós', 'Tribute Band Collective'));
    }

    public function testEmptyNamesScoreZero(): void
    {
        self::assertSame(0.0, $this->similarity->score('', 'Radiohead'));
        self::assertSame(0.0, $this->similarity->score('Radiohead', ''));
    }

    public function testNearMissSpellingFallsToTheFuzzyBand(): void
    {
        self::assertSame(0.60, $this->similarity->score('Vetusta Morla', 'Vetusta Morlla'));
    }
}
