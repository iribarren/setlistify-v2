<?php

declare(strict_types=1);

namespace App\Service\Matching\Model;

/**
 * The output of `SongNormalizer::normalize()` (spec 12 §1) — a comparison-only structure. The raw
 * title, never this, is what goes to the provider (D-107).
 */
final readonly class NormalizedSong
{
    /**
     * @param list<Qualifier> $qualifiers
     * @param list<string>    $featuredArtists
     */
    public function __construct(
        public string $comparisonCore,
        /** @var list<string> */
        public array $tokens,
        public array $qualifiers,
        public array $featuredArtists,
        public bool $hasVersionQualifier,
        public ?string $versionTag,
    ) {
    }
}
