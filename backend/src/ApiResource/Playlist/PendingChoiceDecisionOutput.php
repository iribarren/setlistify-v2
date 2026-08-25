<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use App\Service\Playlist\Model\ReportCode;

/** One CHOICE-band song still awaiting a decision (AC-2.1/AC-2.3). */
final readonly class PendingChoiceDecisionOutput
{
    /**
     * @param ?array<string, mixed>              $reasonParams
     * @param list<PendingChoiceCandidateOutput> $candidates
     */
    public function __construct(
        public int $sourcePosition,
        public ?int $segmentIndex,
        public string $bandName,
        public string $sourceTitle,
        public ?ReportCode $reasonCode,
        public ?array $reasonParams,
        public array $candidates,
    ) {
    }
}
