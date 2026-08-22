<?php

declare(strict_types=1);

namespace App\Service\Provider;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;

/**
 * D-94: a provider that exists (a settings row, possibly with no adapter) but is not available
 * right now — always a `503`, never a `404`. Distinct from `App\Service\Streaming\
 * UnknownProviderException`, which stays a `404` for a key no adapter or settings row recognizes
 * at all (D-94, AC-4.2).
 *
 * Implements API Platform's `ProblemExceptionInterface` directly (the same mechanism
 * `ApiPlatform\State\Exception\ParameterNotSupportedException` uses) so a processor/provider can
 * simply let this propagate and API Platform's error listener renders it as RFC 7807
 * `application/problem+json` with `type: /errors/provider-unavailable` and status `503` — no
 * separate exception-to-HTTP mapping table needed.
 */
final class ProviderDisabledException extends \RuntimeException implements ProblemExceptionInterface
{
    public function __construct(private readonly string $providerKey)
    {
        parent::__construct(\sprintf('Provider "%s" is currently disabled.', $providerKey));
    }

    public function getType(): string
    {
        return '/errors/provider-unavailable';
    }

    public function getTitle(): string
    {
        return 'Provider unavailable';
    }

    public function getStatus(): int
    {
        return 503;
    }

    public function getDetail(): string
    {
        return $this->getMessage();
    }

    public function getInstance(): string
    {
        return $this->providerKey;
    }
}
