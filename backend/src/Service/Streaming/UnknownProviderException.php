<?php

declare(strict_types=1);

namespace App\Service\Streaming;

/**
 * Thrown by `StreamingProviderLocator` when asked for a `key()` no registered adapter declares
 * (D-72, AC-9.3) — a typed domain error, never a class-not-found.
 */
final class UnknownProviderException extends \RuntimeException
{
    public function __construct(string $key)
    {
        parent::__construct(\sprintf('No streaming provider registered for key "%s".', $key));
    }
}
