<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\Provider\Setlist\BandSearchProvider;

/**
 * `GET /api/band-searches?name=` (US-1). Standalone — reachable independently of any `Concert` or
 * `Band` ownership (D-66, spec open question #2): authenticated so the budget can't be drained
 * anonymously, but not owner-filtered, because nothing here is owned.
 *
 * Read-only: no candidate is ever written back onto a `Band` row through this operation — that
 * side effect belongs to `App\Service\Setlist\BandIdentityResolver`, exercised only via
 * `GET /api/bands/{bandId}/setlists` (US-3) or an audited backoffice correction (AC-11.5).
 */
#[ApiResource(
    shortName: 'BandSearch',
    description: "Search setlist.fm's artist index by free-text name (US-1). Cached (AC-1.7) — searching the same string twice makes one outbound call.",
    operations: [
        new Get(
            uriTemplate: '/band-searches',
            output: BandSearchOutput::class,
            provider: BandSearchProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            parameters: [
                'name' => new QueryParameter(
                    key: 'name',
                    required: true,
                    schema: ['type' => 'string', 'minLength' => 1],
                    description: 'Free-text band name to search for on setlist.fm.',
                ),
            ],
        ),
    ],
)]
final class BandSearchResource
{
}
