<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Setlist\SetlistDetailProvider;

/**
 * `GET /api/setlists/{setlistfmId}` (US-4). Once fetched, served from the durable tier forever
 * (D-59, AC-4.5) — a past setlist never changes. Reference data (D-66): authenticated, not
 * owner-filtered.
 */
#[ApiResource(
    shortName: 'Setlist',
    description: "One show's full song list, in playing order (US-4).",
    operations: [
        new Get(
            uriTemplate: '/setlists/{setlistfmId}',
            output: SetlistDetailOutput::class,
            provider: SetlistDetailProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final class SetlistResource
{
}
