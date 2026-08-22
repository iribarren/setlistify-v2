<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * The port's OAuth token pair (AC-9.2) — immutable, provider-agnostic. `refreshToken` is nullable
 * because a refresh response may omit one (AC-4.4); when it is null the caller keeps whatever it
 * already had. `expiresAt` is the absolute instant the access token stops being valid, computed by
 * the adapter from the provider's `expires_in` at the time of the response, not at request time
 * (AC-4.5).
 */
final readonly class ProviderTokens
{
    /** @param list<string> $scopes scopes as granted, not as requested (data model note, D-88) */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public \DateTimeImmutable $expiresAt,
        public array $scopes,
        /**
         * The provider's own account id/display name, populated by `exchangeCode()` (AC-1.4) —
         * `refreshToken()` leaves these null since a refresh never needs to re-fetch identity and
         * no caller reads them from a refresh result. Not a new interface method (D-71): identity
         * is carried on the value object OAuth exchange already returns, not fetched through an
         * eleventh port method.
         */
        public ?string $providerAccountId = null,
        public ?string $providerDisplayName = null,
    ) {
    }
}
