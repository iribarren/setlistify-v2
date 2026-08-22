<?php

declare(strict_types=1);

namespace App\Tests\Functional\Provider;

use App\Entity\ProviderSetting;
use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use App\Repository\ProviderSettingRepository;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderRegistry;
use App\Service\Provider\ProviderSettingValidationException;
use App\Service\Provider\ProviderSettingWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `App\Service\Provider\ProviderSettingWriter` (docs/specs/2026-08-22-backoffice-provider-configuration.md,
 * US-7, US-8). The only write path — every admin edit and every rule in AC-7.1–AC-7.4 lives here.
 */
final class ProviderSettingWriterTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get('provider.redis')->del(ProviderRegistry::CACHE_KEY);
        $this->resetSeed();
    }

    public function testUpdatingAFieldWritesExactlyOneAuditEntryPerChangedField(): void
    {
        $writer = $this->writer();
        $actor = $this->persistActor();

        $entries = $this->entriesCreatedDuring('spotify', function () use ($writer, $actor): void {
            $writer->update('spotify', enabled: true, playbackMode: PlaybackMode::Deeplink, isDefault: true, notes: null, actor: $actor);
        });
        $fields = array_map(static fn ($e) => $e->getField(), $entries);

        self::assertContains('playbackMode', $fields);
        self::assertNotContains('enabled', $fields, 'enabled did not change — no entry for it.');
        self::assertNotContains('isDefault', $fields, 'isDefault did not change — no entry for it.');
    }

    /** AC-8.3/D-104: literal values for enabled/playbackMode/isDefault — never digested. */
    public function testChangedValuesAreRecordedLiterallyExceptNotes(): void
    {
        $writer = $this->writer();
        $actor = $this->persistActor();

        $entries = $this->entriesCreatedDuring('spotify', function () use ($writer, $actor): void {
            $writer->update('spotify', enabled: false, playbackMode: PlaybackMode::Embed, isDefault: false, notes: 'incident-name-not-to-be-leaked', actor: $actor);
        });
        $byField = [];
        foreach ($entries as $entry) {
            $byField[(string) $entry->getField()] = $entry;
        }

        self::assertArrayHasKey('enabled', $byField);
        self::assertSame('true', $byField['enabled']->getOldValue());
        self::assertSame('false', $byField['enabled']->getNewValue());

        self::assertArrayHasKey('notes', $byField);
        self::assertNotSame('incident-name-not-to-be-leaked', $byField['notes']->getNewValue(), 'D-103/AC-8.3: notes must be digested, never literal.');
    }

    /** AC-7.2: setting a new default clears the previous one, in one transaction. */
    public function testSettingANewDefaultClearsThePrevious(): void
    {
        $writer = $this->writer();
        $actor = $this->persistActor();

        $writer->update('youtube', enabled: true, playbackMode: PlaybackMode::Off, isDefault: true, notes: null, actor: $actor);

        self::assertTrue($this->rowFor('youtube')->isDefault());
        self::assertFalse($this->rowFor('spotify')->isDefault(), 'AC-7.2: at most one default at a time.');
    }

    /** D-100/AC-7.4: disabling the current default clears it — never promotes another. */
    public function testDisablingTheCurrentDefaultClearsItWithoutPromotingAnother(): void
    {
        $writer = $this->writer();
        $actor = $this->persistActor();

        $writer->update('spotify', enabled: false, playbackMode: PlaybackMode::Embed, isDefault: true, notes: null, actor: $actor);

        $spotify = $this->rowFor('spotify');
        self::assertFalse($spotify->isEnabled());
        self::assertFalse($spotify->isDefault(), 'D-100: cleared, not silently kept true.');
        self::assertFalse($this->rowFor('youtube')->isDefault(), 'D-100: never auto-promoted.');
    }

    /** AC-7.3: explicitly promoting a currently-disabled, not-yet-default provider is rejected. */
    public function testPromotingADisabledProviderToDefaultIsRejected(): void
    {
        $writer = $this->writer();
        $actor = $this->persistActor();

        // youtube is seeded disabled, not default.
        $this->expectException(ProviderSettingValidationException::class);
        $writer->update('youtube', enabled: false, playbackMode: PlaybackMode::Off, isDefault: true, notes: null, actor: $actor);
    }

    /** AC-8.5/D-92: the cache is invalidated only after a successful commit. */
    public function testWriteInvalidatesTheRegistryCacheAfterCommit(): void
    {
        $registry = new ProviderRegistry(
            repository: static::getContainer()->get(ProviderSettingRepository::class),
            redis: static::getContainer()->get('provider.redis'),
            logger: new \Psr\Log\NullLogger(),
            cacheTtlSeconds: 300,
        );
        self::assertTrue($registry->isAvailable('spotify'));

        $writer = $this->writer();
        $writer->update('spotify', enabled: false, playbackMode: PlaybackMode::Embed, isDefault: false, notes: null, actor: $this->persistActor());

        $freshRegistry = new ProviderRegistry(
            repository: static::getContainer()->get(ProviderSettingRepository::class),
            redis: static::getContainer()->get('provider.redis'),
            logger: new \Psr\Log\NullLogger(),
            cacheTtlSeconds: 300,
        );
        self::assertFalse($freshRegistry->isAvailable('spotify'));
    }

    /** A no-op write (nothing actually changed) persists nothing and audits nothing. */
    public function testANoOpWriteWritesNoAuditEntry(): void
    {
        $writer = $this->writer();
        $actor = $this->persistActor();

        // Same values as the seed.
        $entries = $this->entriesCreatedDuring('spotify', function () use ($writer, $actor): void {
            $writer->update('spotify', enabled: true, playbackMode: PlaybackMode::Embed, isDefault: true, notes: null, actor: $actor);
        });

        self::assertSame([], $entries, 'AC-8.2: nothing changed, nothing audited.');
    }

    private function writer(): ProviderSettingWriter
    {
        return static::getContainer()->get(ProviderSettingWriter::class);
    }

    private function auditRepository(): AuditLogEntryRepository
    {
        return static::getContainer()->get(AuditLogEntryRepository::class);
    }

    /**
     * Runs `$action` and returns only the `AuditLogEntry` rows it created for
     * `(ProviderSetting, $subjectId)` — the audit log is a shared table across the whole suite run
     * (and across earlier test methods in this very class, since `ProviderSetting` rows are shared,
     * migration-seeded singletons, not per-test fixtures), so a plain `findBy()` after the fact
     * would also see unrelated entries from other tests.
     *
     * @return list<\App\Entity\AuditLogEntry>
     */
    private function entriesCreatedDuring(string $subjectId, callable $action): array
    {
        $before = array_map(static fn ($e) => $e->getId(), $this->auditRepository()->findBy(['subjectType' => 'ProviderSetting', 'subjectId' => $subjectId]));

        $action();

        $after = $this->auditRepository()->findBy(['subjectType' => 'ProviderSetting', 'subjectId' => $subjectId]);

        return array_values(array_filter($after, static fn ($e) => !\in_array($e->getId(), $before, true)));
    }

    private function rowFor(string $provider): ProviderSetting
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $setting = static::getContainer()->get(ProviderSettingRepository::class)->findOneByProvider($provider);
        self::assertInstanceOf(ProviderSetting::class, $setting);

        return $setting;
    }

    private function persistActor(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(\sprintf('provider-writer.%s@example.test', Uuid::v4()), 'placeholder-hash');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function resetSeed(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('UPDATE provider_settings SET is_default = false'); // clear first — the partial unique index forbids two rows at once, even transiently across statements.
        $connection->executeStatement("UPDATE provider_settings SET enabled = true, playback_mode = 'embed', is_default = true, notes = NULL WHERE provider = 'spotify'");
        $connection->executeStatement("UPDATE provider_settings SET enabled = false, playback_mode = 'off', is_default = false, notes = NULL WHERE provider = 'youtube'");
    }
}
