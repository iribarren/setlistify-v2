<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `POST /api/token/refresh`'s body. On web the refresh token normally arrives via the httpOnly
 * cookie (D-18) and this field is left empty; native clients (which cannot use the cookie the same
 * way, per AC-4.6) send the token they stored in `expo-secure-store` here instead.
 * `App\State\Processor\RefreshProcessor` reads whichever is present, cookie first.
 */
final class RefreshInput
{
    public ?string $refreshToken = null;
}
