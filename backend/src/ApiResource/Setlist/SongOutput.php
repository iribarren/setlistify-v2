<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

/** One song, in playing order (AC-4.1, AC-4.2, AC-4.3). */
final readonly class SongOutput
{
    public function __construct(
        public int $position,
        public ?string $setLabel,
        public string $title,
        public ?string $coverOfName,
        public ?string $coverOfMbid,
        public ?string $withName,
        public ?string $info,
        public bool $isTape,
    ) {
    }
}
