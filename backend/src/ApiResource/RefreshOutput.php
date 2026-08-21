<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\RefreshProcessor;

/**
 * `POST /api/token/refresh` (US-4). Every call rotates the presented refresh token — it is marked
 * used and a new one takes its place, sharing the same family (AC-4.1). Reuse of an
 * already-rotated token is treated as theft and kills the family (AC-4.4), subject to the
 * grace-window mitigation in {@see \App\Service\Security\RefreshTokenService} (R-3).
 */
#[ApiResource(
    shortName: 'Refresh',
    operations: [
        new Post(
            uriTemplate: '/token/refresh',
            input: RefreshInput::class,
            output: self::class,
            processor: RefreshProcessor::class,
        ),
    ],
)]
final readonly class RefreshOutput
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int $expiresIn,
        /** Present only for `X-Client-Platform: native` requests — see {@see LoginOutput}. */
        public ?string $refreshToken = null,
    ) {
    }
}
