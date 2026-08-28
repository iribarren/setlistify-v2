<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\MeStateProvider;

/**
 * `GET /api/me` (US-8). Requires a valid bearer JWT — enforced by the `api` firewall
 * (`config/packages/security.yaml`), not by this resource. Never returns a password hash or a
 * token, and never another user's data (AC-8.2): the provider reads the identity off the security
 * token, there is no id/email parameter a caller could substitute.
 */
#[ApiResource(
    shortName: 'Me',
    description: 'The authenticated user\'s own identity.',
    operations: [
        new Get(
            uriTemplate: '/me',
            provider: MeStateProvider::class,
            output: self::class,
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
        ),
    ],
)]
final readonly class Me
{
    public function __construct(
        public int $id,
        public string $email,
        public bool $emailVerified,
        /** @var list<string> */
        public array $roles,
        public \DateTimeImmutable $createdAt,
        /**
         * Instant setlist refresh (docs/specs/2026-08-27-instant-setlist-refresh.md, D-269,
         * AC-10.1) — derived from `App\Security\Voter\InstantRefreshVoter`, never the raw
         * `instantRefreshGrantedAt` column, and never writable.
         */
        public bool $canRefreshSetlistNow,
    ) {
    }
}
