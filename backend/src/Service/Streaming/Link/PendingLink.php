<?php

declare(strict_types=1);

namespace App\Service\Streaming\Link;

/**
 * A pending OAuth link, as stored server-side by `PendingLinkStore` and keyed by its `state`
 * (D-76, AC-8.1): bound to the user id, the provider key, the client platform and the PKCE
 * verifier. Redis's own TTL (`STREAMING_LINK_STATE_TTL`) is the expiry — this value object carries
 * no expiry field of its own.
 */
final readonly class PendingLink
{
    public function __construct(
        public int $userId,
        public string $provider,
        public string $platform,
        public string $codeVerifier,
    ) {
    }
}
