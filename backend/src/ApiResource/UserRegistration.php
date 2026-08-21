<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\RegisterUserProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/users` — account registration (US-1). Output-only: the created user's public
 * representation, exactly `id`, `email`, `emailVerified`, `createdAt` and nothing else (AC-1.1) —
 * no `roles` field exists on this class to leak (AC-10.2).
 *
 * `App\Entity\User` is never a writable resource (D-22): {@see RegisterUserInput} is the entire
 * request surface and {@see RegisterUserProcessor} does the real work.
 */
#[ApiResource(
    shortName: 'User',
    description: 'Account registration. Roles are always exactly ["ROLE_USER"], assigned server-side — this endpoint has no path to any other role (US-10).',
    operations: [
        new Post(
            uriTemplate: '/users',
            status: Response::HTTP_CREATED,
            input: RegisterUserInput::class,
            output: self::class,
            processor: RegisterUserProcessor::class,
        ),
    ],
)]
final readonly class UserRegistration
{
    public function __construct(
        public int $id,
        public string $email,
        public bool $emailVerified,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
