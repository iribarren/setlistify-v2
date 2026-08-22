<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `POST /api/streaming/link` response (AC-1.1) — the URL the client opens, and nothing else. */
final readonly class StreamingLinkStartOutput
{
    public function __construct(
        public string $authorizationUrl,
    ) {
    }
}
