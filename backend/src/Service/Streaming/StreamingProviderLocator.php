<?php

declare(strict_types=1);

namespace App\Service\Streaming;

/**
 * `key() -> adapter` resolution, via tagged services (D-72, AC-9.3). Adapters are collected through
 * a `!tagged_iterator app.streaming_provider` (`config/services.yaml`) and indexed by `key()` once,
 * at construction — nothing in this class, or anywhere upstream of it, names an adapter class.
 * `App\Tests\Unit\Service\Streaming\TestDoubleProviderIsDiscoverableTest` proves AC-9.5's claim: a
 * test-double adapter registered only by adding the tag is discoverable here with zero changes to
 * this class.
 */
final class StreamingProviderLocator
{
    /** @var array<string, StreamingProviderInterface> */
    private array $providersByKey = [];

    /** @param iterable<StreamingProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providersByKey[$provider->key()] = $provider;
        }
    }

    /** @throws UnknownProviderException if no adapter registered this key */
    public function get(string $key): StreamingProviderInterface
    {
        return $this->providersByKey[$key] ?? throw new UnknownProviderException($key);
    }

    public function has(string $key): bool
    {
        return isset($this->providersByKey[$key]);
    }

    /** @return list<string> every registered provider's key, e.g. for the availability seam (D-86) */
    public function keys(): array
    {
        return array_keys($this->providersByKey);
    }
}
