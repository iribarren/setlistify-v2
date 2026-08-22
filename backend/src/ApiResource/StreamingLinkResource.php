<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\StreamingLinkStartProcessor;
use Symfony\Component\HttpFoundation\Response;

/** `POST /api/streaming/link` (US-1, AC-1.1). Starts the OAuth round trip for a given provider key. */
#[ApiResource(
    shortName: 'StreamingLink',
    operations: [
        new Post(
            uriTemplate: '/streaming/link',
            status: Response::HTTP_CREATED,
            input: StreamingLinkStartInput::class,
            output: StreamingLinkStartOutput::class,
            processor: StreamingLinkStartProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final readonly class StreamingLinkResource
{
}
