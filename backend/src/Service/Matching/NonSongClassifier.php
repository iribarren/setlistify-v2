<?php

declare(strict_types=1);

namespace App\Service\Matching;

use App\Entity\Song;

/**
 * Decides whether a setlist entry is a song at all (spec 12 §5, D-116).
 *
 * Three ordered signals. The first two decide; the third only advises.
 *
 *  1. **Structural, free, language-independent** — `Song::isTape()`. setlist.fm's own flag, preserved
 *     deliberately by prompt 09 (AC-4.3 left the decision to this class). It catches the largest class
 *     of non-songs — the walk-on tape, the outro music, the interlude — in any language, with zero
 *     guessing, so it is checked first.
 *  2. **A curated performance-artifact lexicon**, matched on the WHOLE normalized title and never as a
 *     substring, with position-sensitive terms requiring a set boundary. `Intro` by The xx and `Jam`
 *     by Michael Jackson are real songs; whole-title matching plus the position disambiguator is what
 *     keeps them safe.
 *  3. **Advisory only, never promoting.** A short title with no candidate above the reject threshold
 *     is *suspicious*, and `isSuspicious()` records that — but the outcome stays `not_found`, never
 *     `skipped`. Upgrading a miss into "that wasn't a song" would be the system covering its own
 *     failures, which is the exact opposite of the honesty the product sells.
 *
 * **Required precision is 1.00**: no real song may ever be classified as a non-song. Recall may be
 * imperfect — a missed artifact becomes a mildly noisy `not_found` line, which is not wrong. That
 * asymmetry is the entire argument for a curated list over a classifier.
 */
final readonly class NonSongClassifier
{
    /** Signal 3's threshold — a title this short with nothing above `choice` is suspicious, not skippable. */
    private const int SUSPICIOUS_MAX_TOKENS = 2;

    /**
     * @param list<string> $alwaysTerms          artifact anywhere in the set
     * @param list<string> $positionSensitiveTerms artifact only at a set boundary
     */
    public function __construct(
        private SongNormalizer $normalizer,
        private array $alwaysTerms,
        private array $positionSensitiveTerms,
    ) {
    }

    /**
     * @param bool $isSetBoundary whether this entry is first or last within its set — the caller knows
     *                            the set's extent, this class does not
     */
    public function isNonSong(Song $song, bool $isSetBoundary): bool
    {
        // Signal 1 — structural, and deliberately first.
        if ($song->isTape()) {
            return true;
        }

        $core = $this->normalizer->normalize($song->getTitle())->comparisonCore;

        if ('' === $core) {
            return false;
        }

        // Signal 2 — whole-title exact, NEVER substring.
        if (\in_array($core, $this->alwaysTerms, true)) {
            return true;
        }

        return $isSetBoundary && \in_array($core, $this->positionSensitiveTerms, true);
    }

    /**
     * Signal 3. Advisory: worth surfacing in the report's phrasing, never a reason to change the
     * outcome from `not_found` to `skipped`.
     */
    public function isSuspicious(Song $song, bool $hadAnyCandidateAboveReject): bool
    {
        if ($hadAnyCandidateAboveReject) {
            return false;
        }

        $normalized = $this->normalizer->normalize($song->getTitle());

        return \count($normalized->tokens) <= self::SUSPICIOUS_MAX_TOKENS && [] !== $normalized->tokens;
    }
}
