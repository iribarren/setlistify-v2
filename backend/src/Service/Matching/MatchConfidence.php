<?php

declare(strict_types=1);

namespace App\Service\Matching;

use App\Service\Matching\Model\MatchOutcome;
use App\Service\Matching\Model\MatchSignals;

/**
 * The spec 12 §3 formula: a weighted sum renormalized over the signals that are actually present,
 * then the artist gate.
 *
 *     raw  = Σ wᵢ·sᵢ / Σ wᵢ          over present signals only
 *     conf = raw                      if s_artist ≥ artistGateFloor
 *          = min(raw, artistGateCap)  otherwise
 *
 * **Why the artist rule is a cap and not a bigger weight.** *Right title, wrong artist* is the highest
 * cost error the system can make, and it is the one a weighted sum handles worst: a perfect title, a
 * plausible version fit and a top rank already carry a wrong-artist candidate to ~0.6 — inside a band
 * that would show it to a user. Raising the artist weight instead makes *every* score
 * artist-dominated, including the cases where a slight mismatch is legitimate (a reissue credited to a
 * solo name, a `Band feat. Guest` credit). A cap leaves normal scoring untouched and puts a ceiling
 * below CHOICE on candidates whose artist is genuinely unrelated: they can still appear in a
 * Normal-mode ranked list, which is honest, but can never be accepted silently.
 *
 * Pure and stateless. Every number it uses comes from the `MatchProfile` it is handed.
 */
final readonly class MatchConfidence
{
    public function score(MatchSignals $signals, MatchProfile $profile): float
    {
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($signals->present() as $signal => $value) {
            $weight = $profile->weight($signal);
            if (0.0 === $weight) {
                continue;
            }

            $numerator += $weight * $value;
            $denominator += $weight;
        }

        if (0.0 === $denominator) {
            return 0.0;
        }

        $raw = $numerator / $denominator;

        if ($signals->artist < $profile->artistGateFloor) {
            return min($raw, $profile->artistGateCap);
        }

        return $raw;
    }

    /** Which of §3's three bands a score falls into. `Skipped` is a Tier-0 verdict and never produced here. */
    public function band(float $confidence, MatchProfile $profile): MatchOutcome
    {
        if ($confidence >= $profile->autoAcceptThreshold) {
            return MatchOutcome::Matched;
        }

        if ($confidence >= $profile->choiceThreshold) {
            return MatchOutcome::MatchedLowConfidence;
        }

        return MatchOutcome::NotFound;
    }
}
