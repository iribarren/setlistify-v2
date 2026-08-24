<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use App\Service\Playlist\Model\ReportCode;

/** A job- or playlist-level report entry — a code and parameters, never rendered English (D-141). */
final readonly class ReportEntryOutput
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public ReportCode $code,
        public array $params,
    ) {
    }
}
