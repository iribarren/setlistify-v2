<?php

declare(strict_types=1);

namespace App\Service\Matching\Model;

/** One classified segment extracted from a raw title by N4 (spec 12 §1). */
final readonly class Qualifier
{
    public function __construct(
        public QualifierKind $kind,
        public string $rawSegment,
        /** Only meaningful for `Version` — one of `studio|live|acoustic|remix|instrumental|...`. */
        public ?string $versionTag = null,
    ) {
    }
}
