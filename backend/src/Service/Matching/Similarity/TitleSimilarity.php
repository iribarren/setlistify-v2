<?php

declare(strict_types=1);

namespace App\Service\Matching\Similarity;

use App\Service\Matching\SongNormalizer;

/**
 * The §2 title metric: a fixed blend of character-trigram Dice and weighted token-set Jaccard.
 *
 * Both halves are **code-point safe** (`mb_str_split`, never bytes) and **symmetric**. That is the
 * whole reason `levenshtein()` and `similar_text()` were rejected: the first scores
 * `('sigur rós', 'sigur ros')` at 2 rather than 1 because `ó` is two bytes, and the second can return
 * different values depending on argument order, which makes candidate ranking depend on the order the
 * candidates happened to arrive in.
 *
 * Pure and static-shaped: no database, no provider, no state. The comparison runs against the ~20
 * candidates of one search response in memory (§2), so it costs ~20-50 µs per candidate and there is
 * no performance argument for a cheaper metric.
 */
final readonly class TitleSimilarity
{
    private const int TRIGRAM_SIZE = 3;

    /** Stop-token weight in the Jaccard half (N6) — present, but never decisive. */
    private const float STOP_TOKEN_WEIGHT = 0.25;

    private const float REGULAR_TOKEN_WEIGHT = 1.0;

    public function __construct(
        private float $trigramWeight = 0.60,
        private float $tokenSetWeight = 0.40,
    ) {
    }

    /**
     * The blended score for two already-normalized comparison cores.
     *
     * Callers that have hit Tier 3 or Tier 4 (exact / qualifier-aware core equality) must short-circuit
     * to 1.0 themselves rather than calling this — the blend is Tier 5 only.
     *
     * @param list<string> $tokensA
     * @param list<string> $tokensB
     */
    public function score(string $coreA, array $tokensA, string $coreB, array $tokensB): float
    {
        $trigram = self::trigramDice($coreA, $coreB);
        $tokenSet = self::weightedJaccard($tokensA, $tokensB);

        return $this->trigramWeight * $trigram + $this->tokenSetWeight * $tokenSet;
    }

    /**
     * `Dice₃ = 2·|A ∩ B| / (|A| + |B|)` over the SET of 3-code-point windows.
     *
     * Each core is padded with one leading and one trailing space so that word boundaries participate
     * in the comparison — without the padding, `"end"` and `"the end"` share every trigram of the
     * shorter string and score far higher than they should.
     */
    public static function trigramDice(string $a, string $b): float
    {
        $setA = self::trigrams($a);
        $setB = self::trigrams($b);

        $countA = \count($setA);
        $countB = \count($setB);

        if (0 === $countA && 0 === $countB) {
            return 1.0;
        }
        if (0 === $countA || 0 === $countB) {
            return 0.0;
        }

        $intersection = \count(array_intersect_key($setA, $setB));

        return 2.0 * $intersection / ($countA + $countB);
    }

    /**
     * `J = Σw(A ∩ B) / Σw(A ∪ B)` over the token SETS (duplicates collapsed), with stop tokens
     * weighted 0.25 rather than 1.0 so that a leading article costs a little and never decides.
     *
     * @param list<string> $tokensA
     * @param list<string> $tokensB
     */
    public static function weightedJaccard(array $tokensA, array $tokensB): float
    {
        $setA = array_flip($tokensA);
        $setB = array_flip($tokensB);

        if ([] === $setA && [] === $setB) {
            return 1.0;
        }
        if ([] === $setA || [] === $setB) {
            return 0.0;
        }

        $intersectionWeight = 0.0;
        foreach (array_keys(array_intersect_key($setA, $setB)) as $token) {
            $intersectionWeight += self::tokenWeight((string) $token);
        }

        $unionWeight = 0.0;
        foreach (array_keys($setA + $setB) as $token) {
            $unionWeight += self::tokenWeight((string) $token);
        }

        return 0.0 === $unionWeight ? 0.0 : $intersectionWeight / $unionWeight;
    }

    /**
     * The set of 3-code-point windows of a padded core, as a lookup map (window => true) so that the
     * intersection is an `array_intersect_key` rather than an O(n²) scan.
     *
     * @return array<string, true>
     */
    private static function trigrams(string $core): array
    {
        $core = trim($core);
        if ('' === $core) {
            return [];
        }

        // mb_str_split, not str_split: windows must be over code points, never bytes.
        $characters = mb_str_split(' '.$core.' ', 1, 'UTF-8');
        $length = \count($characters);

        if ($length < self::TRIGRAM_SIZE) {
            return [implode('', $characters) => true];
        }

        $trigrams = [];
        for ($i = 0; $i + self::TRIGRAM_SIZE <= $length; ++$i) {
            $trigrams[implode('', \array_slice($characters, $i, self::TRIGRAM_SIZE))] = true;
        }

        return $trigrams;
    }

    private static function tokenWeight(string $token): float
    {
        return \in_array($token, SongNormalizer::STOP_TOKENS, true)
            ? self::STOP_TOKEN_WEIGHT
            : self::REGULAR_TOKEN_WEIGHT;
    }
}
