<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use App\Service\Concert\BandResolver;
use App\Service\Matching\Model\QualifierKind;
use App\Service\Matching\SongNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The N0-N8 pipeline. The `worked examples` provider is spec 12 §1's own table, transcribed — it is
 * the regression guard on the transform order, which is the part of this pipeline that silently
 * breaks when someone reorders a step.
 */
#[CoversClass(SongNormalizer::class)]
final class SongNormalizerTest extends TestCase
{
    private SongNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SongNormalizer();
    }

    #[DataProvider('workedExamples')]
    public function testComparisonCore(string $raw, string $expectedCore): void
    {
        self::assertSame($expectedCore, $this->normalizer->normalize($raw)->comparisonCore);
    }

    /** @return iterable<string, array{string, string}> */
    public static function workedExamples(): iterable
    {
        // NFKD strips the acute; N1b folds `æ`, which NFKD does NOT decompose — without N1b the
        // entire Icelandic/Nordic/Polish catalog mismatches.
        yield 'diacritic + ligature' => ['Sæglópur', 'saeglopur'];

        // The single most consequential transform: blind stripping would collapse this to
        // `untitled 1`, equidistant from `Untitled #2`, `#3`, `#4` — a catastrophic false match.
        yield 'unrecognized parenthetical is title continuation' => ['Untitled #1 (Vaka)', 'untitled 1 vaka'];

        yield 'version qualifier leaves the core' => ['Nothing Else Matters (Live)', 'nothing else matters'];
        yield 'trailing dash version' => ['Nothing Else Matters - Live', 'nothing else matters'];
        yield 'continuation parenthetical' => ['Rosalita (Come Out Tonight)', 'rosalita come out tonight'];
        yield 'hyphen unified then removed' => ['Tenth Avenue Freeze-Out', 'tenth avenue freeze out'];
        yield 'featured credit removed' => ['Under Pressure (feat. David Bowie)', 'under pressure'];
        yield 'leading article kept' => ['Los Días Raros', 'los dias raros'];
        yield 'stop tokens kept in core' => ['Everything In Its Right Place', 'everything in its right place'];
        yield 'curly apostrophe unified' => ['Rock ’n’ Roll', 'rock n roll'];
        yield 'ampersand becomes and' => ['Salt & Pepper', 'salt and pepper'];
        yield 'whitespace collapsed' => ['  Kid   A ', 'kid a'];
        yield 'the article is not stripped' => ['The End', 'the end'];
    }

    /**
     * N6, the deliberate divergence from `BandResolver::normalize()` (D-106). In band names an
     * article is decoration; in song titles it is load-bearing — `The End` and `End` are distinct real
     * titles, and stripping creates a collision no later signal can undo.
     */
    public function testTitleKeepsLeadingArticleWhereBandNameStripsIt(): void
    {
        self::assertSame('the end', $this->normalizer->normalize('The End')->comparisonCore);
        self::assertSame('end', BandResolver::normalize('The End'));
    }

    public function testVersionQualifierIsClassifiedAndTagged(): void
    {
        $normalized = $this->normalizer->normalize('Nothing Else Matters (Live)');

        self::assertTrue($normalized->hasVersionQualifier);
        self::assertSame('live', $normalized->versionTag);
        self::assertSame(QualifierKind::Version, $normalized->qualifiers[0]->kind);
    }

    public function testLiveAtPrefixIsAVersionQualifier(): void
    {
        $normalized = $this->normalizer->normalize('Badlands (Live at Wembley)');

        self::assertTrue($normalized->hasVersionQualifier);
        self::assertSame('live', $normalized->versionTag);
        self::assertSame('badlands', $normalized->comparisonCore);
    }

    public function testYearRemasterIsAVersionQualifier(): void
    {
        $normalized = $this->normalizer->normalize('Kashmir (2012 Remaster)');

        self::assertTrue($normalized->hasVersionQualifier);
        self::assertSame('studio', $normalized->versionTag);
        self::assertSame('kashmir', $normalized->comparisonCore);
    }

    public function testFeaturedArtistIsExtractedNotDiscarded(): void
    {
        $normalized = $this->normalizer->normalize('Under Pressure (feat. David Bowie)');

        self::assertSame(['David Bowie'], $normalized->featuredArtists);
        self::assertSame(QualifierKind::FeaturedCredit, $normalized->qualifiers[0]->kind);
    }

    /**
     * N5 is POSITIONAL, never a bare substring search — otherwise `Sleeping with the Television On`
     * becomes `sleeping`.
     */
    public function testFeaturedMarkerMidTitleIsNotStripped(): void
    {
        self::assertSame(
            'sleeping with the television on',
            $this->normalizer->normalize('Sleeping with the Television On')->comparisonCore,
        );
    }

    public function testUnrecognizedParentheticalDefaultsToContinuation(): void
    {
        $normalized = $this->normalizer->normalize('(Sittin\' On) The Dock of the Bay');

        self::assertSame(QualifierKind::TitleContinuation, $normalized->qualifiers[0]->kind);
        self::assertStringContainsString('sittin on', $normalized->comparisonCore);
        self::assertStringContainsString('the dock of the bay', $normalized->comparisonCore);
    }

    public function testTokensAreTheCoreSplitOnWhitespace(): void
    {
        self::assertSame(['kid', 'a'], $this->normalizer->normalize('Kid A')->tokens);
    }

    public function testEmptyTitleProducesEmptyCoreAndNoTokens(): void
    {
        $normalized = $this->normalizer->normalize('   ');

        self::assertSame('', $normalized->comparisonCore);
        self::assertSame([], $normalized->tokens);
    }

    /** Both crowd-entered spellings of the same song must normalize identically — that is the point. */
    public function testAccentedAndUnaccentedSpellingsConverge(): void
    {
        self::assertSame(
            $this->normalizer->normalize('Sæglópur')->comparisonCore,
            $this->normalizer->normalize('Saeglopur')->comparisonCore,
        );
    }
}
