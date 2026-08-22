<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\Provider\Setlist\BandSetlistsProvider;

/**
 * `GET /api/bands/{bandId}/setlists` (US-3). Ordered newest first, paginated over the **cached**
 * index (AC-3.5) — a user paging through results never spends budget per page. Reference data
 * (D-66): authenticated, not owner-filtered — a `Band` is shared, not owned by anyone.
 */
#[ApiResource(
    shortName: 'BandSetlists',
    description: "A band's past setlists, newest first (US-3).",
    operations: [
        new Get(
            uriTemplate: '/bands/{bandId}/setlists',
            output: BandSetlistsOutput::class,
            provider: BandSetlistsProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            queryParameterValidationEnabled: false,
            parameters: [
                'page' => new QueryParameter(
                    key: 'page',
                    schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    description: 'Page number, over the cached index (D-31, AC-3.5).',
                ),
                'itemsPerPage' => new QueryParameter(
                    key: 'itemsPerPage',
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    description: 'Page size, capped at 100 (D-31).',
                ),
            ],
        ),
    ],
)]
final class BandSetlistsResource
{
}
