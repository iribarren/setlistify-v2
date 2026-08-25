<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use App\Service\Playlist\Model\ConfidenceLabel;
use App\Service\Playlist\Model\ReportCode;

/** Reviewable, never a question — the green band (AC-2.2). */
final readonly class PendingChoiceAutoResolvedOutput
{
    /** @param ?array<string, mixed> $reasonParams */
    public function __construct(
        public int $sourcePosition,
        public string $bandName,
        public string $sourceTitle,
        public ?string $providerTrackId,
        public ConfidenceLabel $label,
        public ?ReportCode $reasonCode,
        public ?array $reasonParams,
    ) {
    }
}
