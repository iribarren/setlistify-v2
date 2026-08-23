<?php

declare(strict_types=1);

namespace App\Service\Matching\Similarity;

use App\Service\Concert\BandResolver;

/**
 * `s_artist` (spec 12 §3) — the sub-score feeding both signal 2 and the **artist gate**.
 *
 * This class reuses `BandResolver::normalize()` **verbatim** and adds nothing to it (D-106): comparing
 * an expected band name to a candidate's credited artist *is* band-name normalization, and
 * `Sigur Rós`/`Sigur Ros` and `The Rolling Stones`/`Rolling Stones` are exactly the cases it was
 * written for. The title side deliberately does NOT share it — see `SongNormalizer`'s N6.
 */
final readonly class ArtistSimilarity
{
    /** Below this, `MatchConfidence` caps the whole score — right title, wrong artist is the costly error. */
    public const float PREFIX_SUPERSET_SCORE = 0.85;

    private const float EXACT_SCORE = 1.00;
    private const float CREDITED_SCORE = 0.90;
    private const float FUZZY_SCORE = 0.60;
    private const float FUZZY_TRIGRAM_FLOOR = 0.75;
    private const float NO_MATCH_SCORE = 0.00;

    /**
     * @param string       $expectedArtist  the cover's original artist when setlist.fm marked the entry
     *                                      as a cover, otherwise the performing band's name (D-113)
     * @param list<string> $otherCredits    the candidate's remaining credited artists
     */
    public function score(string $expectedArtist, string $primaryArtist, array $otherCredits = []): float
    {
        $expected = BandResolver::normalize($expectedArtist);
        $primary = BandResolver::normalize($primaryArtist);

        if ('' === $expected || '' === $primary) {
            return self::NO_MATCH_SCORE;
        }

        if ($expected === $primary) {
            return self::EXACT_SCORE;
        }

        foreach ($otherCredits as $credit) {
            if ($expected === BandResolver::normalize($credit)) {
                return self::CREDITED_SCORE;
            }
        }

        // `bruce springsteen` ⊂ `bruce springsteen the e street band`, in either direction.
        if (self::isPrefixSuperset($expected, $primary)) {
            return self::PREFIX_SUPERSET_SCORE;
        }

        if (TitleSimilarity::trigramDice($expected, $primary) >= self::FUZZY_TRIGRAM_FLOOR) {
            return self::FUZZY_SCORE;
        }

        return self::NO_MATCH_SCORE;
    }

    /**
     * Word-boundary-aware containment. A bare `str_starts_with` would score `the b` against
     * `the beatles`, so the shorter string must end on a token boundary of the longer one.
     */
    private static function isPrefixSuperset(string $a, string $b): bool
    {
        [$shorter, $longer] = mb_strlen($a, 'UTF-8') <= mb_strlen($b, 'UTF-8') ? [$a, $b] : [$b, $a];

        return str_starts_with($longer, $shorter.' ');
    }
}
