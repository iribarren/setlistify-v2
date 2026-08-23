<?php

declare(strict_types=1);

namespace App\Service\Playlist;

use App\Entity\Setlist;
use App\Service\Playlist\Model\SelectionReason;

final readonly class SelectionResult
{
    public function __construct(
        public Setlist $setlist,
        public SelectionReason $reason,
    ) {
    }
}
