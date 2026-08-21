<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\PasswordResetRequestProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/password-reset/request` (US-6). Always 202 with the same body whether or not the
 * address exists (AC-6.1, US-9) — {@see PasswordResetRequestProcessor} never lets a caller
 * distinguish the two.
 */
#[ApiResource(
    shortName: 'PasswordResetRequest',
    operations: [
        new Post(
            uriTemplate: '/password-reset/request',
            status: Response::HTTP_ACCEPTED,
            input: PasswordResetRequestInput::class,
            output: GenericAck::class,
            processor: PasswordResetRequestProcessor::class,
        ),
    ],
)]
final readonly class PasswordResetRequestAction
{
}
