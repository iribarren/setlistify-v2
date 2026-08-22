<?php

declare(strict_types=1);

namespace App\Service\Provider;

/**
 * D-86: anything that offers a provider to a user, or decides how a playlist is played back, reads
 * this seam at runtime rather than assuming a provider is available — `docs/architecture.md` §6's
 * `ProviderRegistry` implements it. Its predecessor, `StaticProviderAvailability` — a placeholder
 * that answered "every registered adapter is available" — shipped with prompt 10 and was deleted in
 * prompt 11 (D-89) once `ProviderRegistry` replaced it as the sole implementation.
 */
interface ProviderAvailability
{
    public function isAvailable(string $providerKey): bool;
}
