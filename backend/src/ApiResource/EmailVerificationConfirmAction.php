<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\EmailVerificationConfirmProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/email-verification/confirm` (AC-7.2). A used, expired or unknown token all give one
 * indistinguishable 400. Implemented as `POST` only (a body-carrying, non-idempotent action);
 * `docs/specs/2026-08-21-auth-and-accounts.md`'s `GET|POST` was written for a click-through email
 * link, but a `GET` that mutates state is both a CSRF surface and awkward with a JSON body — the
 * frontend's verification screen extracts the token from the deep link and POSTs it instead
 * (recorded as a deviation in the implementation report).
 */
#[ApiResource(
    shortName: 'EmailVerificationConfirm',
    operations: [
        new Post(
            uriTemplate: '/email-verification/confirm',
            status: Response::HTTP_NO_CONTENT,
            input: EmailVerificationConfirmInput::class,
            output: false,
            processor: EmailVerificationConfirmProcessor::class,
        ),
    ],
)]
final readonly class EmailVerificationConfirmAction
{
}
