<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The fixed, identical-regardless-of-outcome response body for endpoints that must not reveal
 * whether the requested thing (an account, a pending resend) actually existed — password-reset
 * request (AC-6.1) and verification resend (AC-7.3).
 */
final readonly class GenericAck
{
    public function __construct(
        public string $message,
    ) {
    }
}
