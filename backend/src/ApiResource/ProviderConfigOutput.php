<?php

declare(strict_types=1);

namespace App\ApiResource;

use App\Service\Provider\PlaybackMode;

/**
 * `GET /api/config/providers` item shape (US-6, AC-6.2). Exactly these five fields, forever —
 * `App\Tests\Functional\Config\ProviderConfigApiTest` (AC-6.4) asserts the exact key set against a
 * live response and fails on any addition, removal or rename, the same allowlist shape
 * `StreamingAccountOutput` uses (AC-7.1 there). `notes` is never here (D-103, AC-6.8) and no field
 * name may resemble a credential (AC-9.2).
 */
final readonly class ProviderConfigOutput
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
