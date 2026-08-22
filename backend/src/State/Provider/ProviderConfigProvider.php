<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProviderConfigOutput;
use App\Service\Provider\ProviderRegistry;
use App\Service\Streaming\StreamingProviderLocator;

/**
 * `GET /api/config/providers` (US-6). Reads `ProviderRegistry` only — never the settings entity or
 * its repository directly (US-10) — and intersects with `StreamingProviderLocator`'s registered
 * adapter keys (D-99): a settings row with no adapter is omitted, an adapter with no settings row
 * is omitted. Deny by default, which is what makes seeding the `youtube` row safe before its
 * adapter exists (D-102).
 *
 * @implements ProviderInterface<ProviderConfigOutput>
 */
final readonly class ProviderConfigProvider implements ProviderInterface
{
    public function __construct(
        private ProviderRegistry $registry,
        private StreamingProviderLocator $locator,
    ) {
    }

    /** @return list<ProviderConfigOutput> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $registeredKeys = $this->locator->keys();

        $output = [];
        foreach ($this->registry->all() as $config) {
            if (!\in_array($config->key, $registeredKeys, true)) {
                continue;
            }

            $output[] = new ProviderConfigOutput(
                key: $config->key,
                displayName: $config->displayName,
                enabled: $config->enabled,
                playbackMode: $config->playbackMode,
                isDefault: $config->isDefault,
            );
        }

        return $output;
    }
}
