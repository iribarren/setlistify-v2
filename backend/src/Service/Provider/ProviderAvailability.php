<?php

declare(strict_types=1);

namespace App\Service\Provider;

/**
 * D-86: anything that offers a provider to a user, or decides how a playlist is played back, reads
 * this seam at runtime rather than assuming a provider is available — the same `CLAUDE.md` rule
 * `docs/architecture.md` §6's `ProviderRegistry` will implement in full. This branch does not build
 * `ProviderSetting`/`ProviderRegistry` (prompt 11's job) — {@see StaticProviderAvailability} is a
 * placeholder implementation that answers "every registered adapter is available", so prompt 11
 * replaces one class and changes no caller.
 */
interface ProviderAvailability
{
    public function isAvailable(string $providerKey): bool;
}
