<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Service\Streaming\StreamingProviderLocator;

/**
 * D-86's constant implementation: every provider registered with the locator is "available". Reads
 * the locator rather than hardcoding a list, so a provider directory that exists but is not wired
 * into the tag is correctly reported as unavailable — no separate list to keep in sync.
 */
final readonly class StaticProviderAvailability implements ProviderAvailability
{
    public function __construct(
        private StreamingProviderLocator $locator,
    ) {
    }

    public function isAvailable(string $providerKey): bool
    {
        return $this->locator->has($providerKey);
    }
}
