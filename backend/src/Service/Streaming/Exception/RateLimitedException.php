<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/**
 * The provider is rejecting requests for rate reasons right now — transient (D-80), never changes
 * a `StreamingAccount`'s status. Carries the retry-after hint as a plain integer of seconds
 * (AC-10.4) when the provider supplied one, never the provider's own header shape.
 */
final class RateLimitedException extends StreamingException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
