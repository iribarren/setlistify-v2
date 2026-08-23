<?php

declare(strict_types=1);

namespace App\Service\Matching\Model;

/**
 * The eight scoring inputs for one candidate (spec 12 §3), each already normalized to [0,1] by the
 * caller — or `null`, meaning **absent**.
 *
 * Absent is not the same as zero, and the distinction is the whole reason this is a struct of
 * nullables rather than an array of floats: an absent signal is dropped from the numerator *and* the
 * denominator, so a candidate is never punished for metadata the provider simply did not return.
 * `duration` is the standing example — setlist.fm supplies no duration at all, so there is nothing on
 * our side to compare against and the usual denominator is 0.92 rather than 1.00.
 */
final readonly class MatchSignals
{
    public function __construct(
        public float $title,
        public float $artist,
        public float $rank,
        public ?float $version = null,
        public ?float $duration = null,
        public ?float $releaseType = null,
        public ?float $authority = null,
        public ?float $popularity = null,
    ) {
    }

    /**
     * Signal key => value, absent signals omitted entirely. The keys match `profiles.yaml`'s weight
     * keys, which is what lets the formula renormalize over presence without a lookup table.
     *
     * @return array<string, float>
     */
    public function present(): array
    {
        $signals = [
            'title' => $this->title,
            'artist' => $this->artist,
            'version' => $this->version,
            'duration' => $this->duration,
            'releaseType' => $this->releaseType,
            'authority' => $this->authority,
            'popularity' => $this->popularity,
            'rank' => $this->rank,
        ];

        return array_filter($signals, static fn (?float $value): bool => null !== $value);
    }
}
