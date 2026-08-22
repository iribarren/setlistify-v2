<?php

declare(strict_types=1);

namespace App\Service\Provider;

use App\Entity\ProviderSetting;
use App\Entity\User;
use App\Repository\ProviderSettingRepository;
use App\Service\Admin\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The only write path for {@see ProviderSetting} (US-10, AC-8.1) — every admin edit goes through
 * {@see self::update()}. Audits one {@see \App\Entity\AuditLogEntry} per **changed** field only
 * (AC-8.2), literally for `enabled`/`playbackMode`/`isDefault` and digested for `notes` (D-104,
 * D-103), all inside one transaction with the setting write itself, and invalidates
 * `ProviderRegistry`'s cache only **after** that transaction commits (D-92, AC-8.5).
 *
 * **Old values are read with a raw DBAL query, not through the ORM identity map.** The admin form
 * (`App\Controller\Admin\ProviderSettingCrudController`) binds submitted values directly onto the
 * already-loaded, identity-mapped entity before this class ever sees it — a `findOneByProvider()`
 * call here would return that *same*, already-mutated object, making a naive "before vs after"
 * comparison always see zero changes. Reading the previous row directly from the database sidesteps
 * that entirely; applying the new values still goes through the (possibly already-attached) entity,
 * so a single `flush()` is enough either way.
 */
final readonly class ProviderSettingWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProviderSettingRepository $repository,
        private AuditLogger $auditLogger,
        private \Redis $redis,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProviderSettingValidationException AC-7.3 — explicitly promoting a currently-disabled,
     *                                            not-yet-default provider to default is rejected;
     *                                            disabling the *current* default instead silently
     *                                            clears it (D-100, AC-7.4)
     */
    public function update(
        string $provider,
        bool $enabled,
        PlaybackMode $playbackMode,
        bool $isDefault,
        ?string $notes,
        User $actor,
    ): void {
        $before = $this->currentRow($provider);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if (!$enabled) {
            if ($isDefault && !$before['is_default']) {
                // AC-7.3: actively promoting a disabled provider to default.
                throw new ProviderSettingValidationException('Cannot set a disabled provider as the default.');
            }
            // D-100/AC-7.4: disabling the current default clears it — never auto-promotes another.
            $isDefault = false;
        }

        /** @var array<string, array{0: ?string, 1: ?string}> $changes field => [old, new], literal (D-104) */
        $changes = [];
        if ($before['enabled'] !== $enabled) {
            $changes['enabled'] = [self::boolLiteral($before['enabled']), self::boolLiteral($enabled)];
        }
        if ($before['playback_mode'] !== $playbackMode->value) {
            $changes['playbackMode'] = [$before['playback_mode'], $playbackMode->value];
        }
        if ($before['is_default'] !== $isDefault) {
            $changes['isDefault'] = [self::boolLiteral($before['is_default']), self::boolLiteral($isDefault)];
        }
        if ($before['notes'] !== $notes) {
            $changes['notes'] = [$before['notes'], $notes];
        }

        if ([] === $changes) {
            // AC-8.2 taken to its limit: nothing changed, nothing to persist or audit.
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($provider, $enabled, $playbackMode, $isDefault, $notes, $now, $changes, $actor): void {
            $setting = $this->repository->findOneByProvider($provider)
                ?? throw new \InvalidArgumentException(\sprintf('No ProviderSetting row for "%s".', $provider));

            if ($isDefault && !$setting->isDefault()) {
                // AC-7.2: clear any other current default in the same transaction — never two at once.
                $previousDefault = $this->repository->findCurrentDefault();
                if (null !== $previousDefault && $previousDefault->getProvider() !== $provider) {
                    $previousDefault->setIsDefault(false);
                    $previousDefault->touch($now);
                    $this->auditLogger->log(
                        actor: $actor,
                        action: 'update_provider_setting',
                        subjectType: 'ProviderSetting',
                        subjectId: $previousDefault->getProvider(),
                        field: 'isDefault',
                        oldValue: self::boolLiteral(true),
                        newValue: self::boolLiteral(false),
                    );
                }
            }

            $setting->setEnabled($enabled);
            $setting->setPlaybackMode($playbackMode);
            $setting->setIsDefault($isDefault);
            $setting->setNotes($notes);
            $setting->touch($now);

            foreach ($changes as $field => [$old, $new]) {
                $this->auditLogger->log(
                    actor: $actor,
                    action: 'update_provider_setting',
                    subjectType: 'ProviderSetting',
                    subjectId: $provider,
                    field: $field,
                    // D-103: notes is the one field digested — it may name incidents, people or vendors.
                    oldValue: 'notes' === $field ? self::digestOrNull($this->auditLogger, $old) : $old,
                    newValue: 'notes' === $field ? self::digestOrNull($this->auditLogger, $new) : $new,
                );
            }

            $this->entityManager->flush();
        });

        // D-92/AC-8.5: invalidate only after a successful commit — a rolled-back write must never
        // leave a stale cache serving a value that was never persisted.
        try {
            $this->redis->del(ProviderRegistry::CACHE_KEY);
        } catch (\Throwable) {
            // Best-effort; PROVIDER_SETTINGS_CACHE_TTL is the backstop (D-92).
        }
    }

    /** @return array{enabled: bool, playback_mode: string, is_default: bool, notes: ?string} */
    private function currentRow(string $provider): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT enabled, playback_mode, is_default, notes FROM provider_settings WHERE provider = :provider',
            ['provider' => $provider],
        );

        if (false === $row) {
            throw new \InvalidArgumentException(\sprintf('No ProviderSetting row for "%s".', $provider));
        }

        $playbackMode = $row['playback_mode'];
        if (!\is_string($playbackMode)) {
            throw new \UnexpectedValueException('provider_settings.playback_mode must be a string.');
        }

        $notes = $row['notes'];
        if (null !== $notes && !\is_string($notes)) {
            throw new \UnexpectedValueException('provider_settings.notes must be a string or null.');
        }

        return [
            'enabled' => (bool) $row['enabled'],
            'playback_mode' => $playbackMode,
            'is_default' => (bool) $row['is_default'],
            'notes' => $notes,
        ];
    }

    private static function boolLiteral(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private static function digestOrNull(AuditLogger $auditLogger, ?string $value): ?string
    {
        return null !== $value ? $auditLogger->digest($value) : null;
    }
}
