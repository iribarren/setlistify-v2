<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\PasswordResetConfirmProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/password-reset/confirm` (AC-6.3–AC-6.6). An expired, unknown or already-used token
 * all produce one indistinguishable 400 (AC-6.5). On success: the token is consumed, every other
 * outstanding reset token for the user is invalidated, and every refresh-token family for the user
 * is revoked — a password reset logs out every device (AC-6.4).
 */
#[ApiResource(
    shortName: 'PasswordResetConfirm',
    operations: [
        new Post(
            uriTemplate: '/password-reset/confirm',
            status: Response::HTTP_NO_CONTENT,
            input: PasswordResetConfirmInput::class,
            output: false,
            processor: PasswordResetConfirmProcessor::class,
        ),
    ],
)]
final readonly class PasswordResetConfirmAction
{
}
