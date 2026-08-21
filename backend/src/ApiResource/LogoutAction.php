<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\LogoutProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/logout` (US-5). Revokes the presented refresh token's entire family. Always 204, even
 * when the presented token is missing or already invalid (AC-5.4) — logging out must never fail
 * visibly, since the whole point is to make a device's session unusable.
 */
#[ApiResource(
    shortName: 'Logout',
    operations: [
        new Post(
            uriTemplate: '/logout',
            status: Response::HTTP_NO_CONTENT,
            input: LogoutInput::class,
            output: false,
            processor: LogoutProcessor::class,
        ),
    ],
)]
final readonly class LogoutAction
{
}
