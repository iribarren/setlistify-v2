<?php

declare(strict_types=1);

namespace App\Service\Provider;

/**
 * Immutable snapshot of one provider's configuration (D-93) — never a managed Doctrine entity, so
 * nothing outside {@see ProviderSettingWriter} ever holds a write handle to the underlying settings
 * row. `notes` is deliberately absent — it never leaves that row (D-103).
 */
final readonly class ProviderConfig
{
    public function __construct(
        public string $key,
        public string $displayName,
        public bool $enabled,
        public PlaybackMode $playbackMode,
        public bool $isDefault,
    ) {
    }
}
