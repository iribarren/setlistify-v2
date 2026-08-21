<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Concert;

use App\Service\Concert\BandResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D-25: normalization is a pure function, so it is unit-testable without a database. The examples
 * are lifted directly from the spec.
 */
final class BandResolverNormalizeTest extends TestCase
{
    public function testTheBeatlesNormalizesByStrippingTheLeadingArticle(): void
    {
        self::assertSame('beatles', BandResolver::normalize('The Beatles'));
    }

    public function testSigurRosNormalizesByStrippingDiacritics(): void
    {
        self::assertSame('sigur ros', BandResolver::normalize('Sigur Rós'));
    }

    public function testAcDcNormalizesByRemovingPunctuation(): void
    {
        self::assertSame('acdc', BandResolver::normalize('AC/DC'));
    }

    public function testCollapsesInternalWhitespace(): void
    {
        self::assertSame('pink floyd', BandResolver::normalize('Pink    Floyd'));
    }

    public function testTrimsLeadingAndTrailingWhitespace(): void
    {
        self::assertSame('queen', BandResolver::normalize('  Queen  '));
    }

    public function testIsCaseInsensitive(): void
    {
        self::assertSame(BandResolver::normalize('METALLICA'), BandResolver::normalize('metallica'));
    }

    #[DataProvider('leadingArticleProvider')]
    public function testStripsEachLeadingArticle(string $input, string $expected): void
    {
        self::assertSame($expected, BandResolver::normalize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function leadingArticleProvider(): iterable
    {
        yield 'the' => ['The Who', 'who'];
        yield 'los' => ['Los Lobos', 'lobos'];
        yield 'las' => ['Las Ketchup', 'ketchup'];
        yield 'el' => ['El Cuervo', 'cuervo'];
        yield 'la' => ['La Oreja de Van Gogh', 'oreja de van gogh'];
    }

    public function testPurePunctuationNormalizesToEmptyString(): void
    {
        self::assertSame('', BandResolver::normalize('---'));
    }

    public function testDoesNotStripAnArticleThatIsNotAtTheStart(): void
    {
        // "The The" only strips the leading article, leaving the band's real second word (D-25:
        // this is an accepted false split, not a bug).
        self::assertSame('the', BandResolver::normalize('The The'));
    }
}
