<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\EmailVerificationResendProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/email-verification/resend` (AC-7.3). Requires a bearer JWT (any authenticated user,
 * verified or not — this is the endpoint an unverified user calls). Always 202 and never reveals
 * whether the account was already verified.
 */
#[ApiResource(
    shortName: 'EmailVerificationResend',
    operations: [
        new Post(
            uriTemplate: '/email-verification/resend',
            status: Response::HTTP_ACCEPTED,
            input: false,
            output: GenericAck::class,
            processor: EmailVerificationResendProcessor::class,
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
        ),
    ],
)]
final readonly class EmailVerificationResendAction
{
}
