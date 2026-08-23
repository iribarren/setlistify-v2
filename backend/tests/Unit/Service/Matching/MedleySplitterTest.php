<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use App\Service\Matching\MedleySplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * setlist.fm has **no medley field** — the ` / ` and ` > ` convention is a community habit, not a
 * schema guarantee. These tests pin the behaviour we assume; the fixture set is what verifies the
 * assumption holds against real data.
 */
#[CoversClass(MedleySplitter::class)]
final class MedleySplitterTest extends TestCase
{
    private MedleySplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new MedleySplitter();
    }

    public function testSplitsOnSlashSeparator(): void
    {
        self::assertSame(
            ['Rock and Roll', 'Whole Lotta Love'],
            $this->splitter->split('Rock and Roll / Whole Lotta Love'),
        );
    }

    public function testSplitsOnSegueSeparator(): void
    {
        self::assertSame(
            ['Us and Them', 'Any Colour You Like'],
            $this->splitter->split('Us and Them > Any Colour You Like'),
        );
    }

    public function testSplitsThreeSegments(): void
    {
        self::assertCount(3, $this->splitter->split('One / Two / Three'));
    }

    public function testANonMedleyReturnsItselfAsASingleSegment(): void
    {
        self::assertSame(['Paranoid Android'], $this->splitter->split('Paranoid Android'));
        self::assertFalse($this->splitter->isMedley('Paranoid Android'));
    }

    /**
     * The separator must be whitespace-delimited. `AC/DC`-shaped titles and dates carry a bare slash
     * that is part of the title, not a segue marker.
     */
    public function testBareSlashWithoutSurroundingSpacesIsNotASeparator(): void
    {
        self::assertSame(['Hells Bells/Thunderstruck'], $this->splitter->split('Hells Bells/Thunderstruck'));
    }

    /** Splitting is attempted only when every segment survives the minimum-length guard. */
    public function testASegmentShorterThanTwoCharactersAbandonsTheSplit(): void
    {
        self::assertSame(['Rock and Roll / A'], $this->splitter->split('Rock and Roll / A'));
    }

    public function testWhitespaceAroundTheWholeTitleIsTrimmed(): void
    {
        self::assertSame(['Kashmir', 'Whole Lotta Love'], $this->splitter->split('  Kashmir / Whole Lotta Love  '));
    }

    public function testIsMedleyAgreesWithSplit(): void
    {
        self::assertTrue($this->splitter->isMedley('Rock and Roll / Whole Lotta Love'));
    }
}
