<?php

declare(strict_types=1);

namespace App\Tests\Functional\Provider;

use App\Repository\ProviderSettingRepository;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `App\Service\Provider\ProviderRegistry` (docs/specs/2026-08-22-backoffice-provider-configuration.md,
 * US-10). Against the real Redis and PostgreSQL from `compose.yaml` — cache-vs-database behaviour is
 * exactly what an in-memory double would fake away, same rationale as
 * `App\Tests\Setlist\SetlistIntegrationTestCase`.
 */
final class ProviderRegistryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetProviderRedis();
        $this->resetProviderSettingsToSeed();
    }

    public function testAllReturnsEverySeededRow(): void
    {
        $registry = $this->makeRegistry();

        $configs = $registry->all();
        $keys = array_map(static fn ($c) => $c->key, $configs);

        self::assertContains('spotify', $keys);
        self::assertContains('youtube', $keys);
    }

    public function testIsAvailableIsTrueForAnEnabledProviderAndFalseForADisabledOne(): void
    {
        $registry = $this->makeRegistry();

        self::assertTrue($registry->isAvailable('spotify'), 'seeded enabled=true (D-102).');
        self::assertFalse($registry->isAvailable('youtube'), 'seeded enabled=false (D-102).');
    }

    /** D-99: deny by default — a key with no settings row is unavailable, not an error. */
    public function testIsAvailableIsFalseForAKeyWithNoSettingsRow(): void
    {
        $registry = $this->makeRegistry();

        self::assertFalse($registry->isAvailable('not-a-real-provider'));
        self::assertNull($registry->configFor('not-a-real-provider'));
    }

    /** AC-2.3/AC-2.4: enabled and playbackMode are independent axes — no coupling. */
    public function testEnabledAndPlaybackModeAreIndependentAxes(): void
    {
        $this->writeRow('spotify', enabled: true, playbackMode: 'off', isDefault: true);

        $registry = $this->makeRegistry();
        $config = $registry->configFor('spotify');

        self::assertNotNull($config);
        self::assertTrue($config->enabled);
        self::assertSame(PlaybackMode::Off, $config->playbackMode);
        self::assertTrue($registry->isAvailable('spotify'), 'enabled=true with playbackMode=off must still be "available" — playbackMode never gates availability (D-97).');
    }

    /**
     * AC-1.3/AC-10.4: invalidation on write is explicit and immediate, verified across a
     * **freshly constructed** registry instance — not just an in-process cache.
     */
    public function testAWriteIsVisibleThroughAFreshlyConstructedRegistryInstance(): void
    {
        $registryA = $this->makeRegistry();
        self::assertTrue($registryA->isAvailable('spotify'));
        // Warm the cache under registryA's read.
        $registryA->all();

        $this->writeRow('spotify', enabled: false, playbackMode: 'embed', isDefault: false);
        $this->invalidateCache();

        $registryB = $this->makeRegistry();
        self::assertFalse($registryB->isAvailable('spotify'), 'a write, once its cache key is invalidated, must be visible through a brand new registry instance.');
    }

    /** D-105/AC-10.5: fails open to the database, never closed, when Redis is unreachable. */
    public function testFallsBackToTheDatabaseWhenRedisIsUnavailable(): void
    {
        $brokenRedis = new \Redis(); // never connected — every call throws.
        $registry = new ProviderRegistry(
            repository: static::getContainer()->get(ProviderSettingRepository::class),
            redis: $brokenRedis,
            logger: new NullLogger(),
            cacheTtlSeconds: 300,
        );

        self::assertTrue($registry->isAvailable('spotify'), 'a broken Redis must not disable every provider — it falls open to a direct database read.');
    }

    /** D-93: never a managed entity — a plain, readonly value object with only the public snapshot fields. */
    public function testConfigForReturnsAnImmutableSnapshotNotAnEntity(): void
    {
        $registry = $this->makeRegistry();
        $config = $registry->configFor('spotify');

        self::assertNotNull($config);
        $reflection = new \ReflectionClass($config);
        self::assertTrue($reflection->isReadOnly(), 'ProviderConfig must be an immutable value object (D-93).');
        self::assertSame(['key', 'displayName', 'enabled', 'playbackMode', 'isDefault'], array_map(static fn ($p) => $p->getName(), $reflection->getProperties()));
    }

    private function makeRegistry(): ProviderRegistry
    {
        return new ProviderRegistry(
            repository: static::getContainer()->get(ProviderSettingRepository::class),
            redis: $this->redis(),
            logger: new NullLogger(),
            cacheTtlSeconds: 300,
        );
    }

    private function writeRow(string $provider, bool $enabled, string $playbackMode, bool $isDefault): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            \sprintf(
                'UPDATE provider_settings SET enabled = %s, playback_mode = :mode, is_default = %s WHERE provider = :provider',
                $enabled ? 'true' : 'false',
                $isDefault ? 'true' : 'false',
            ),
            ['mode' => $playbackMode, 'provider' => $provider],
        );
        // A raw-SQL write bypasses the ORM identity map entirely — clear it so the next
        // repository read re-queries the database instead of returning an already-loaded, now
        // stale entity for this row (the same class of staleness KernelBrowser reboots cause).
        $em->clear();
    }

    private function invalidateCache(): void
    {
        $this->redis()->del(ProviderRegistry::CACHE_KEY);
    }

    private function resetProviderRedis(): void
    {
        $this->redis()->del(ProviderRegistry::CACHE_KEY);
    }

    private function resetProviderSettingsToSeed(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('UPDATE provider_settings SET is_default = false'); // clear first — the partial unique index forbids two rows at once, even transiently across statements.
        $connection->executeStatement("UPDATE provider_settings SET enabled = true, playback_mode = 'embed', is_default = true, notes = NULL WHERE provider = 'spotify'");
        $connection->executeStatement("UPDATE provider_settings SET enabled = false, playback_mode = 'off', is_default = false, notes = NULL WHERE provider = 'youtube'");
    }

    private function redis(): \Redis
    {
        // PHPStan's Symfony extension resolves this literal service id's concrete return type
        // from the compiled container, so no runtime assert is needed (established convention in
        // this codebase — see App\Tests\Setlist\SetlistIntegrationTestCase for the precedent this
        // deviates from only by skipping its now-redundant assert).
        return static::getContainer()->get('provider.redis');
    }
}
