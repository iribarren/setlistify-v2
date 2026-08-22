<?php

declare(strict_types=1);

namespace App\Service\Streaming\Link;

/**
 * The outcome of a completed (or abandoned) OAuth round trip, resolved by
 * `LinkResultStore::consume()` (AC-1.7, AC-8.7). The client never sees a token, code or verifier —
 * only this small, opaque-reference-resolved status.
 */
final readonly class LinkResult
{
    public function __construct(
        public string $provider,
        public bool $success,
        public ?string $reason = null,
    ) {
    }
}
