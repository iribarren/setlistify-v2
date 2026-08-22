<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `GET /api/streaming/accounts` item shape (AC-2.1, AC-2.3). Every field here is deliberately
 * enumerated — no token, no expiry (which would let a caller infer one). `App\Tests\Functional\
 * Streaming\StreamingAccountSerializationAllowlistTest` (AC-7.1) asserts this exact field set
 * against a live response, so a new property added here without updating that test fails the build
 * rather than silently shipping.
 */
final readonly class StreamingAccountOutput
{
    public function __construct(
        public int $id,
        public string $provider,
        public ?string $providerDisplayName,
        public string $providerAccountId,
        /** @var list<string> */
        public array $scopes,
        public \DateTimeImmutable $linkedAt,
        public string $status,
    ) {
    }
}
