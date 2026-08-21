<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\RefreshToken;

/**
 * The plaintext refresh token, returned only at the moment it is minted (login/refresh). Never
 * persisted, never logged — the entity stores only {@see RefreshToken::getTokenHash()}.
 */
final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $plaintext,
        public RefreshToken $entity,
    ) {
    }
}
