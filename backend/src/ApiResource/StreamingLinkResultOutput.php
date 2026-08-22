<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `GET /api/streaming/link-results/{ref}` response (AC-1.7, AC-1.8) — what the client resolves the
 * one-time opaque reference to. Never a token, code or verifier.
 */
final readonly class StreamingLinkResultOutput
{
    public function __construct(
        public string $provider,
        public bool $success,
        public ?string $reason,
    ) {
    }
}
