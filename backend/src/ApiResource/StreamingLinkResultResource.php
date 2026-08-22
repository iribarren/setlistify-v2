<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\StreamingLinkResultProvider;

/** `GET /api/streaming/link-results/{ref}` (AC-1.7, AC-1.8, AC-8.7). */
#[ApiResource(
    shortName: 'StreamingLinkResult',
    operations: [
        new Get(
            uriTemplate: '/streaming/link-results/{ref}',
            uriVariables: ['ref'],
            output: StreamingLinkResultOutput::class,
            provider: StreamingLinkResultProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final readonly class StreamingLinkResultResource
{
}
