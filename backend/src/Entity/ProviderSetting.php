<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProviderSettingRepository;
use App\Service\Provider\PlaybackMode;
use Doctrine\ORM\Mapping as ORM;

/**
 * Behaviour flags for one streaming provider (`docs/architecture.md` §6, D-89). **Holds only
 * `enabled`, `playbackMode`, `isDefault` and `notes` — no credential column, ever** (`CLAUDE.md`:
 * "the backoffice edits behaviour, never credentials"; enforced by a schema test, AC-9.1).
 * `displayName` is deliberately not a column here (see `App\Service\Provider\ProviderRegistry`'s
 * docblock for why it isn't read from the port either).
 *
 * Only two classes may reference this entity or {@see ProviderSettingRepository}: `App\Service\
 * Provider\ProviderRegistry` (the only read path) and `App\Service\Provider\ProviderSettingWriter`
 * (the only write path) — enforced by `App\Tests\Unit\Service\Provider\
 * ProviderSettingIsOnlyDoorTest` (AC-10.1), which also documents the one necessary, disclosed
 * exception: `App\Controller\Admin\ProviderSettingCrudController` must name this class for
 * EasyAdmin's `getEntityFqcn()` — EasyAdmin's own CRUD machinery has no other way to bind a list/
 * detail/edit screen to an entity. That controller never persists or flushes this entity itself;
 * every write it makes goes through `ProviderSettingWriter` (see that controller's `updateEntity()`
 * override).
 *
 * Rows are seeded by migration only (D-102) — there is no `NEW` action (AC-3.6): a provider without
 * an adapter is not a product decision this screen should be able to invent.
 */
#[ORM\Entity(repositoryClass: ProviderSettingRepository::class)]
#[ORM\Table(name: 'provider_settings')]
#[ORM\UniqueConstraint(name: 'uniq_provider_settings_provider', columns: ['provider'])]
class ProviderSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** The port's `key()`, e.g. `'spotify'` — a plain string, not an enum, so adding a provider needs no migration (D-72 shape, reused). */
    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $provider;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled;

    #[ORM\Column(name: 'playback_mode', type: 'string', length: 20, enumType: PlaybackMode::class)]
    private PlaybackMode $playbackMode;

    /** At most one row may be true — enforced by a partial unique index at the storage layer (AC-7.1), not just here. */
    #[ORM\Column(name: 'is_default', type: 'boolean')]
    private bool $isDefault;

    /** Admin-only operational note. Digested in the audit log (D-103) and never in any API response (D-103, AC-6.8). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $provider,
        bool $enabled,
        PlaybackMode $playbackMode,
        bool $isDefault,
        ?string $notes,
        \DateTimeImmutable $now,
    ) {
        $this->provider = $provider;
        $this->enabled = $enabled;
        $this->playbackMode = $playbackMode;
        $this->isDefault = $isDefault;
        $this->notes = $notes;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getPlaybackMode(): PlaybackMode
    {
        return $this->playbackMode;
    }

    public function setPlaybackMode(PlaybackMode $playbackMode): void
    {
        $this->playbackMode = $playbackMode;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
