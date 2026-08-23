<?php

declare(strict_types=1);

namespace App\Message;

/**
 * An id and an attempt number, and nothing else (spec 14 §3, D-125) — every other input the
 * pipeline needs is a column, which is what makes a redelivered message idempotent and keeps a
 * serialized message from ever outliving the truth.
 */
final readonly class BuildPlaylistMessage
{
    public function __construct(
        public int $jobId,
        public int $attempt,
    ) {
    }
}
