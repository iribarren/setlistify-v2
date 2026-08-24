<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Playlist;

use App\Service\Playlist\Model\NoSetlistCause;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\NoSetlistCauseFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** T-3: D-184's three fold rules. */
#[CoversClass(NoSetlistCauseFolder::class)]
final class NoSetlistCauseFolderTest extends TestCase
{
    private NoSetlistCauseFolder $folder;

    protected function setUp(): void
    {
        $this->folder = new NoSetlistCauseFolder();
    }

    public function testSingleEntryEachOfTheFourCauses(): void
    {
        foreach (NoSetlistCause::cases() as $cause) {
            self::assertSame($cause, $this->folder->fold($this->summaryFor([$cause])));
        }
    }

    public function testAMixedLineupWhereOneBandIsNoSetlistForShowWinsOutright(): void
    {
        $summary = $this->summaryFor([
            NoSetlistCause::BandUnknown,
            NoSetlistCause::NoSetlistForShow,
            NoSetlistCause::BandAmbiguous,
        ]);

        self::assertSame(NoSetlistCause::NoSetlistForShow, $this->folder->fold($summary));
    }

    public function testAMixedLineupWithNoResolvedBandFallsBackToTheLastEntry(): void
    {
        // SetlistSelectionStage iterates array_reverse($kept): support acts first, headliner last —
        // so the LAST NO_SETLIST_FOR_BAND entry belongs to the headliner.
        $summary = $this->summaryFor([
            NoSetlistCause::BandUnknown,
            NoSetlistCause::IdentityUnavailable,
            NoSetlistCause::BandAmbiguous,
        ]);

        self::assertSame(NoSetlistCause::BandAmbiguous, $this->folder->fold($summary));
    }

    public function testAnEmptySummaryFoldsToNull(): void
    {
        self::assertNull($this->folder->fold([]));
    }

    public function testEntriesOfOtherReportCodesAreIgnored(): void
    {
        $summary = [
            ['code' => ReportCode::SelectedFrom->value, 'params' => ['band' => 'Headliner']],
            ['code' => ReportCode::BandsOmittedForLength->value, 'params' => ['bands' => ['Support']]],
        ];

        self::assertNull($this->folder->fold($summary));
    }

    /**
     * @param list<NoSetlistCause> $causes
     *
     * @return array<int, array{code: string, params: array<string, mixed>}>
     */
    private function summaryFor(array $causes): array
    {
        return array_map(
            static fn (NoSetlistCause $cause): array => [
                'code' => ReportCode::NoSetlistForBand->value,
                'params' => ['band' => 'Some Band', 'cause' => $cause->value],
            ],
            $causes,
        );
    }
}
