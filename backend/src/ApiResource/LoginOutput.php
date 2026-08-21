<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\LoginProcessor;

/**
 * `POST /api/login` (US-2). Wrong password, unknown email, unverified account (when
 * `AUTH_REQUIRE_VERIFIED_EMAIL` is on) and a disabled account all fail identically — a generic 401
 * with no distinguishing detail (AC-2.4, US-9) — enforced entirely in {@see LoginProcessor}.
 */
#[ApiResource(
    shortName: 'Login',
    operations: [
        new Post(
            uriTemplate: '/login',
            input: LoginInput::class,
            output: self::class,
            processor: LoginProcessor::class,
        ),
    ],
)]
final readonly class LoginOutput
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        /** Seconds until the access token expires (AC-2.2). */
        public int $expiresIn,
        /**
         * Present only for `X-Client-Platform: native` requests (AC-4.6, D-18) — web clients get
         * the refresh token exclusively via the httpOnly cookie and this is always `null`.
         */
        public ?string $refreshToken = null,
    ) {
    }
}
