<?php

declare(strict_types=1);

namespace App\Service\Matching;

/**
 * Resolves a provider key to its `MatchProfile` (D-110/D-118).
 *
 * The same algorithm serves every provider; the *calibration* does not. A new provider therefore
 * arrives as an entry in `matching.profile_overrides`, merged over the default — never as a branch in
 * PHP. Keys are runtime strings, which is what keeps this namespace free of provider symbols.
 *
 * An unknown key is not an error: it falls back to the default profile. A provider whose calibration
 * has not been tuned yet should still match, using the shared numbers, rather than fail to generate.
 */
final readonly class MatchProfileRegistry
{
    /**
     * @param array<string, mixed>                $defaultProfile
     * @param array<string, array<string, mixed>> $overrides      provider key => partial profile
     */
    public function __construct(
        private array $defaultProfile,
        private array $overrides,
        private int $algorithmVersion,
    ) {
    }

    public function forProvider(string $providerKey): MatchProfile
    {
        $config = $this->defaultProfile;

        foreach ($this->overrides[$providerKey] ?? [] as $section => $value) {
            $config[$section] = \is_array($value) && \is_array($config[$section] ?? null)
                ? array_replace($config[$section], $value)
                : $value;
        }

        /** @var array<string, float> $weights */
        $weights = $config['weights'] ?? [];
        /** @var array<string, float> $titleBlend */
        $titleBlend = $config['titleBlend'] ?? [];
        /** @var array<string, float> $thresholds */
        $thresholds = $config['thresholds'] ?? [];

        return new MatchProfile(
            key: $providerKey,
            weights: $weights,
            titleBlend: $titleBlend,
            autoAcceptThreshold: (float) ($thresholds['autoAccept'] ?? 0.80),
            choiceThreshold: (float) ($thresholds['choice'] ?? 0.55),
            artistGateFloor: (float) ($thresholds['artistGateFloor'] ?? 0.50),
            artistGateCap: (float) ($thresholds['artistGateCap'] ?? 0.45),
            durationPlausibilityFactor: is_numeric($config['durationPlausibilityFactor'] ?? null)
                ? (float) $config['durationPlausibilityFactor']
                : null,
        );
    }

    /**
     * Part of every resolution cache key, so a calibration change can never mix two generations of
     * cached answers (D-121).
     */
    public function algorithmVersion(): int
    {
        return $this->algorithmVersion;
    }
}
