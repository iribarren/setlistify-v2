<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Entity\ProviderSetting;
use App\Repository\ProviderSettingRepository;
use Psr\Log\LoggerInterface;

/**
 * D-89: the real `ProviderAvailability` implementation, replacing the placeholder
 * `StaticProviderAvailability` (deleted in this branch, D-89/AC-10.2). The only read path for
 * provider configuration (US-10) — every consumer that needs to know whether a provider is
 * offered, or how playback should render, asks this class.
 *
 * **Caching (D-92, AC-10.3, AC-10.4).** One Redis key ({@see self::CACHE_KEY}) holds the serialized
 * snapshot of every row. A read miss rebuilds it from the database and repopulates the key; a
 * successful write (`ProviderSettingWriter`) deletes the key immediately after its transaction
 * commits. `PROVIDER_SETTINGS_CACHE_TTL` is a backstop against an invalidation bug, never the
 * correctness mechanism — nothing here waits for it.
 *
 * **Fails open, not closed (D-105, AC-10.5).** If Redis is unreachable, this class falls back to a
 * direct database read and logs a warning rather than throwing — provider configuration is not a
 * security boundary, and disabling every provider during a Redis blip would be a worse outcome than
 * a slower request. This deliberately differs from `App\Service\Security\RateLimiterGuard`, which
 * fails closed because it *is* guarding a boundary.
 *
 * **`displayName` is derived, not read from the port (disclosed deviation from the spec's Technical
 * Approach, which assumed `StreamingProviderInterface` exposes a display name).** The frozen
 * nine-method port (D-71) has no such method, and adding a tenth for a single cosmetic label is not
 * worth reopening that freeze in a backend-only branch. A capitalized form of the provider key
 * (`ucfirst`, applied at runtime — this file's own source never spells out any reference provider's
 * capitalized product name) is used instead, so the architecture isolation test that scans `src/`
 * for a reference provider's literal symbol outside its own adapter directory is never at risk. If
 * a provider ever needs a display name its key can't produce by capitalization (e.g. an acronym),
 * the fix is a small static map inside the provider's own adapter directory, read through the port
 * — not a hardcoded name here.
 */
final class ProviderRegistry implements ProviderAvailability
{
    public const string CACHE_KEY = 'provider:settings:v1';

    public function __construct(
        private readonly ProviderSettingRepository $repository,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtlSeconds,
    ) {
    }

    public function isAvailable(string $providerKey): bool
    {
        $config = $this->configFor($providerKey);

        return null !== $config && $config->enabled;
    }

    /** D-93: never a managed entity. Null if no settings row exists for this key (deny by default, D-99). */
    public function configFor(string $providerKey): ?ProviderConfig
    {
        foreach ($this->all() as $config) {
            if ($config->key === $providerKey) {
                return $config;
            }
        }

        return null;
    }

    /** @return list<ProviderConfig> every settings row, regardless of adapter registration (D-93) */
    public function all(): array
    {
        return array_map(self::toConfig(...), $this->snapshot());
    }

    /** @return list<array{provider: string, enabled: bool, playbackMode: string, isDefault: bool}> */
    private function snapshot(): array
    {
        try {
            $cached = $this->redis->get(self::CACHE_KEY);
        } catch (\Throwable $e) {
            $this->logger->warning('ProviderRegistry: Redis unavailable, falling back to a direct database read (D-105).', [
                'exception' => $e::class,
            ]);

            return $this->readFromDatabase();
        }

        if (\is_string($cached)) {
            try {
                $decoded = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    /** @var list<array{provider: string, enabled: bool, playbackMode: string, isDefault: bool}> $fromCache */
                    $fromCache = $decoded;

                    return $fromCache;
                }
            } catch (\JsonException) {
                // Fall through to a rebuild — a corrupt cache entry is treated as a miss.
            }
        }

        $fresh = $this->readFromDatabase();

        try {
            $this->redis->set(self::CACHE_KEY, json_encode($fresh, JSON_THROW_ON_ERROR), $this->cacheTtlSeconds);
        } catch (\Throwable $e) {
            // Best-effort write-back; the read path above already covers a genuinely unavailable Redis.
            $this->logger->warning('ProviderRegistry: failed to populate the settings cache.', [
                'exception' => $e::class,
            ]);
        }

        return $fresh;
    }

    /** @return list<array{provider: string, enabled: bool, playbackMode: string, isDefault: bool}> */
    private function readFromDatabase(): array
    {
        return array_map(
            static fn (ProviderSetting $setting): array => [
                'provider' => $setting->getProvider(),
                'enabled' => $setting->isEnabled(),
                'playbackMode' => $setting->getPlaybackMode()->value,
                'isDefault' => $setting->isDefault(),
            ],
            $this->repository->findAllOrderedByProvider(),
        );
    }

    /** @param array{provider: string, enabled: bool, playbackMode: string, isDefault: bool} $row */
    private static function toConfig(array $row): ProviderConfig
    {
        return new ProviderConfig(
            key: $row['provider'],
            displayName: self::displayNameFor($row['provider']),
            enabled: $row['enabled'],
            playbackMode: PlaybackMode::from($row['playbackMode']),
            isDefault: $row['isDefault'],
        );
    }

    private static function displayNameFor(string $key): string
    {
        return implode(' ', array_map(ucfirst(...), preg_split('/[-_]+/', $key) ?: [$key]));
    }
}
