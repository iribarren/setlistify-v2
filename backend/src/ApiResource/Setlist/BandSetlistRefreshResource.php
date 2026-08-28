<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Processor\Setlist\ResolveBandIdentityProcessor;
use App\State\Processor\Setlist\TriggerSetlistRefreshProcessor;
use App\State\Provider\Setlist\BandSetlistRefreshProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instant, entitled, on-demand setlist refresh (docs/specs/2026-08-27-instant-setlist-refresh.md,
 * US-1 through US-6). `POST` triggers an async re-check (D-256) — zero outbound setlist.fm requests
 * happen on this request thread (AC-3.1). `GET` polls, honouring `Retry-After` while active
 * (AC-3.5), the same contract `PlaylistGenerationJobResource` established. `POST …/resolution`
 * (D-278) is a SEPARATE operation for the ambiguity pick — never a body variant of the trigger.
 *
 * Every refusal is `429` with `Retry-After` and a typed `refusedReason` (D-260), carried on
 * {@see BandSetlistRefreshOutput} rather than as a distinct error shape, via
 * `App\EventSubscriber\SetlistRefreshResponseHeadersSubscriber`'s status override — the same
 * request-attribute-driven mechanism `PlaylistResponseHeadersSubscriber` uses.
 */
#[ApiResource(
    shortName: 'BandSetlistRefresh',
    description: 'On-demand, entitled, budget-throttled re-check of a band\'s setlist.fm identity and index (US-1 through US-6).',
    operations: [
        new Post(
            uriTemplate: '/bands/{bandId}/setlist-refresh',
            status: Response::HTTP_ACCEPTED,
            input: false,
            output: BandSetlistRefreshOutput::class,
            processor: TriggerSetlistRefreshProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY') and is_granted('CAN_REFRESH_SETLIST_NOW', user)",
            description: 'Triggers a forced re-resolution and, once resolved, a forced-live index fetch (D-263). Async (202); zero outbound calls on this request thread (AC-3.1). A second trigger while one is in flight returns 200 with the existing refresh, never 409 (D-262). Every throttle refusal is 429 with Retry-After and a typed refusedReason (D-260).',
        ),
        new Get(
            uriTemplate: '/bands/{bandId}/setlist-refresh',
            output: BandSetlistRefreshOutput::class,
            provider: BandSetlistRefreshProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: 'Polls for the current or most recent refresh. 200 with state: null for a band never refreshed (AC-3.6) — never 404 for that reason. Retry-After while queued/running, absent once terminal.',
        ),
        new Post(
            uriTemplate: '/bands/{bandId}/setlist-refresh/resolution',
            status: Response::HTTP_ACCEPTED,
            input: ResolveBandIdentityInput::class,
            output: BandSetlistRefreshOutput::class,
            processor: ResolveBandIdentityProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY') and is_granted('CAN_REFRESH_SETLIST_NOW', user)",
            description: "The ambiguity pick (D-270-D-280): selects one candidate from the band's most recent refresh, never a free-text MBID. Vacancy-only and once-only — 422 mbid_not_a_candidate / band_already_resolved. Makes no outbound call itself and is exempt from the cooldown; completes as a one-request setlist fetch that still counts against the daily cap and passes the budget gate.",
        ),
    ],
)]
final class BandSetlistRefreshResource
{
}
