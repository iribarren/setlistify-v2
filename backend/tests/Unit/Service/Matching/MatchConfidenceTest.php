<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use App\Service\Matching\MatchConfidence;
use App\Service\Matching\MatchProfile;
use App\Service\Matching\Model\MatchOutcome;
use App\Service\Matching\Model\MatchSignals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchConfidence::class)]
#[CoversClass(MatchSignals::class)]
final class MatchConfidenceTest extends TestCase
{
    private MatchConfidence $confidence;
    private MatchProfile $profile;

    protected function setUp(): void
    {
        $this->confidence = new MatchConfidence();
        $this->profile = self::profile();
    }

    private static function profile(): MatchProfile
    {
        return new MatchProfile(
            key: 'test',
            weights: [
                'title' => 0.40, 'artist' => 0.25, 'version' => 0.12, 'duration' => 0.08,
                'releaseType' => 0.06, 'authority' => 0.05, 'popularity' => 0.02, 'rank' => 0.02,
            ],
            titleBlend: ['trigram' => 0.60, 'tokenSet' => 0.40],
            autoAcceptThreshold: 0.80,
            choiceThreshold: 0.55,
            artistGateFloor: 0.50,
            artistGateCap: 0.45,
        );
    }

    public function testPerfectCandidateScoresOne(): void
    {
        $signals = new MatchSignals(
            title: 1.0, artist: 1.0, rank: 1.0, version: 1.0,
            releaseType: 1.0, authority: 1.0, popularity: 1.0,
        );

        self::assertSame(1.0, round($this->confidence->score($signals, $this->profile), 10));
    }

    /**
     * The load-bearing property: an absent signal is dropped from the numerator AND the denominator,
     * so a candidate is never punished for metadata the provider did not return. setlist.fm supplies
     * no duration at all, which is why the usual denominator is 0.92 rather than 1.00.
     */
    public function testAbsentSignalsRenormalizeRatherThanScoreZero(): void
    {
        $withoutDuration = new MatchSignals(
            title: 1.0, artist: 1.0, rank: 1.0, version: 1.0,
            releaseType: 1.0, authority: 1.0, popularity: 1.0,
        );

        // Every present signal perfect ⇒ 1.0, regardless of how many are missing.
        self::assertSame(1.0, round($this->confidence->score($withoutDuration, $this->profile), 10));

        // A zero-valued duration is NOT the same as an absent one.
        $withZeroDuration = new MatchSignals(
            title: 1.0, artist: 1.0, rank: 1.0, version: 1.0, duration: 0.0,
            releaseType: 1.0, authority: 1.0, popularity: 1.0,
        );

        self::assertSame(0.92, round($this->confidence->score($withZeroDuration, $this->profile), 10));
    }

    /**
     * The artist gate. A perfect title with an unrelated artist is the highest-cost error the system
     * can make, and a weighted sum alone carries it to ~0.6 — inside a band that would show it to a
     * user. The cap puts it below `choice` by construction.
     */
    public function testArtistGateCapsAnOtherwiseStrongWrongArtistCandidate(): void
    {
        $tributeBand = new MatchSignals(
            title: 1.0, artist: 0.0, rank: 1.0, version: 1.0,
            releaseType: 1.0, authority: 0.4, popularity: 0.5,
        );

        $score = $this->confidence->score($tributeBand, $this->profile);

        self::assertSame(0.45, round($score, 10), 'A wrong-artist candidate must be capped at artistGateCap.');
        self::assertLessThan($this->profile->choiceThreshold, $score);
        self::assertSame(MatchOutcome::NotFound, $this->confidence->band($score, $this->profile));
    }

    public function testArtistGateDoesNotFireAtOrAboveTheFloor(): void
    {
        $relatedArtist = new MatchSignals(title: 1.0, artist: 0.60, rank: 1.0, version: 1.0);

        $score = $this->confidence->score($relatedArtist, $this->profile);

        self::assertGreaterThan($this->profile->artistGateCap, $score);
    }

    /**
     * A *related* artist (`s_artist = 0.60`) does NOT by itself hold a candidate out of the
     * auto-accept band — spec 12 §3's threshold justification (c) claimed it lands "around 0.72", and
     * the arithmetic says 0.87. With the artist signal weighted 0.25, dropping it from 1.00 to 0.60
     * costs only 0.10 of the numerator, which perfect metadata elsewhere more than absorbs.
     *
     * This is recorded rather than "fixed": the numbers are an initial calibration and the fixture
     * harness is what decides them. The test pins the real behaviour so that a future weight change
     * is visible instead of silent. See the D-160 correction note in spec 12 §3.
     */
    public function testRelatedArtistWithPerfectMetadataStillAutoAccepts(): void
    {
        $signals = new MatchSignals(
            title: 1.0, artist: 0.60, rank: 0.95, version: 1.0,
            releaseType: 1.0, authority: 0.9, popularity: 0.5,
        );

        $score = $this->confidence->score($signals, $this->profile);

        self::assertSame(0.8739, round($score, 4));
        self::assertSame(MatchOutcome::Matched, $this->confidence->band($score, $this->profile));
    }

    /** The middle band is reached by a related artist plus genuinely weaker metadata, not by the artist alone. */
    public function testRelatedArtistWithWeakMetadataLandsInTheChoiceBand(): void
    {
        $signals = new MatchSignals(
            title: 1.0, artist: 0.60, rank: 0.60, version: 0.0,
            releaseType: 0.45, authority: 0.4, popularity: 0.3,
        );

        $score = $this->confidence->score($signals, $this->profile);

        self::assertSame(MatchOutcome::MatchedLowConfidence, $this->confidence->band($score, $this->profile));
    }

    /**
     * A garbled title must not auto-accept on metadata alone: with the usual 0.92 denominator, a
     * perfect artist plus perfect version/type/authority still needs a strong title to clear 0.80.
     */
    public function testGarbledTitleCannotAutoAcceptOnMetadataAlone(): void
    {
        $signals = new MatchSignals(
            title: 0.30, artist: 1.0, rank: 1.0, version: 1.0,
            releaseType: 1.0, authority: 1.0, popularity: 1.0,
        );

        $score = $this->confidence->score($signals, $this->profile);

        self::assertLessThan($this->profile->autoAcceptThreshold, $score);
    }

    public function testBandingBoundariesAreInclusiveAtTheLowerEdge(): void
    {
        self::assertSame(MatchOutcome::Matched, $this->confidence->band(0.80, $this->profile));
        self::assertSame(MatchOutcome::MatchedLowConfidence, $this->confidence->band(0.799, $this->profile));
        self::assertSame(MatchOutcome::MatchedLowConfidence, $this->confidence->band(0.55, $this->profile));
        self::assertSame(MatchOutcome::NotFound, $this->confidence->band(0.549, $this->profile));
    }

    /** The gate's cap sits below the reject threshold, so a gated candidate rejects by construction. */
    public function testArtistGateCapSitsBelowTheChoiceThreshold(): void
    {
        self::assertLessThan($this->profile->choiceThreshold, $this->profile->artistGateCap);
    }

    public function testSignalsWithZeroWeightAreIgnoredEntirely(): void
    {
        $profile = new MatchProfile(
            key: 'title-only',
            weights: ['title' => 1.0],
            titleBlend: ['trigram' => 0.60, 'tokenSet' => 0.40],
            autoAcceptThreshold: 0.80,
            choiceThreshold: 0.55,
            artistGateFloor: 0.50,
            artistGateCap: 0.45,
        );

        $signals = new MatchSignals(title: 0.90, artist: 1.0, rank: 0.0, popularity: 0.0);

        self::assertSame(0.90, round($this->confidence->score($signals, $profile), 10));
    }
}
