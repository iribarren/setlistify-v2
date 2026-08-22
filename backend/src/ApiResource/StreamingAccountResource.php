<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\State\Processor\StreamingAccountUnlinkProcessor;
use App\State\Provider\StreamingAccountCollectionProvider;

/**
 * `/api/streaming/accounts` (US-2, US-3). Output-only against `StreamingAccountOutput`; this class
 * has no entity binding (mirrors `App\ApiResource\ConcertResource`, D-29's shape). Ownership
 * (D-77) is enforced in the provider/processor via `App\Security\StreamingAccountOwnerExtension`,
 * not here.
 */
#[ApiResource(
    shortName: 'StreamingAccount',
    description: 'A user\'s link to one streaming provider — status, scopes and identity, never a token (US-2, US-3).',
    operations: [
        new GetCollection(
            uriTemplate: '/streaming/accounts',
            output: StreamingAccountOutput::class,
            provider: StreamingAccountCollectionProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new Delete(
            uriTemplate: '/streaming/accounts/{id}',
            read: false,
            output: false,
            processor: StreamingAccountUnlinkProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final class StreamingAccountResource
{
}
