<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

/** A job- or playlist-level report entry — a code and parameters, never rendered English (D-141). */
final readonly class ReportEntryOutput
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public string $code,
        public array $params,
    ) {
    }
}
