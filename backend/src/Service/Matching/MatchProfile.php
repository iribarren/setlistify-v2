<?php

declare(strict_types=1);

namespace App\Service\Matching;

/**
 * One provider's calibration: the weight vector, the title blend and the thresholds (D-110).
 *
 * Loaded from `config/matching/profiles.yaml`, never constructed with literals in application code,
 * and **keyed by a runtime provider string** — a profile key is a value, not a class name, which is
 * what keeps this class inside the provider-agnostic namespace without naming a provider (D-118).
 *
 * Thresholds live in reviewed configuration rather than in the backoffice's provider-settings row, on
 * purpose: unlike `enabled` or `playbackMode`, whose effect is visible in the very next request, a
 * threshold change alters the quality of every future playlist in a way nobody notices for weeks. The
 * only legitimate way to move one is against §9's fixture harness with before/after numbers — that is
 * a pull request, not a click — and it must bump `algorithmVersion` so cached resolutions never mix
 * two calibrations. A config file reviewed in a PR can enforce that; a form field cannot.
 * (The full argument is in spec 12 §3; it is deliberately not restated with the settings entity's
 * name here, so the AC-10.1 static door scan stays a pure symbol search.)
 */
final readonly class MatchProfile
{
    /**
     * @param array<string, float> $weights    signal key => weight, summing to 1.00 when every signal is present
     * @param array<string, float> $titleBlend `trigram` and `tokenSet`, summing to 1.00
     */
    public function __construct(
        public string $key,
        public array $weights,
        public array $titleBlend,
        public float $autoAcceptThreshold,
        public float $choiceThreshold,
        public float $artistGateFloor,
        public float $artistGateCap,
        /**
         * Candidates whose duration falls outside `[track/durationTolerance, track·durationTolerance]`
         * of the median candidate duration are dropped before scoring. Used only by providers whose
         * catalog contains full-album uploads; `null` disables the filter (§6).
         */
        public ?float $durationPlausibilityFactor = null,
    ) {
    }

    public function weight(string $signal): float
    {
        return $this->weights[$signal] ?? 0.0;
    }

    public function trigramWeight(): float
    {
        return $this->titleBlend['trigram'] ?? 0.60;
    }

    public function tokenSetWeight(): float
    {
        return $this->titleBlend['tokenSet'] ?? 0.40;
    }
}
