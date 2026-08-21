<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\State\Processor\ConcertCreateProcessor;
use App\State\Processor\ConcertDeleteProcessor;
use App\State\Processor\ConcertUpdateProcessor;
use App\State\Provider\ConcertCollectionProvider;
use App\State\Provider\ConcertItemProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/concerts` — the thing a Setlistify user owns (US-1 through US-7). Every operation is
 * output-only against `ConcertOutput`; writes go through `ConcertInput` (create) or
 * `ConcertPatchInput` (update) — this class itself has no entity binding (D-29), continuing D-22.
 *
 * Ownership (US-7, D-27) is enforced in every provider/processor via `App\State\ConcertLocator` /
 * `App\Security\ConcertOwnerExtension`, not here; `security: "is_granted('IS_AUTHENTICATED_FULLY')"`
 * on every operation only accounts for AC-7.3 (401 for anonymous requests).
 */
#[ApiResource(
    shortName: 'Concert',
    description: 'A concert the authenticated user attended or is planning to attend — bands, date, venue, and what it cost (US-1 through US-7).',
    operations: [
        new Post(
            uriTemplate: '/concerts',
            status: Response::HTTP_CREATED,
            input: ConcertInput::class,
            output: ConcertOutput::class,
            processor: ConcertCreateProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new GetCollection(
            uriTemplate: '/concerts',
            output: ConcertOutput::class,
            provider: ConcertCollectionProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            // Schemas below are documentation only (AC-10.1) — App\State\Provider\ConcertCollectionProvider
            // does its own defensive parsing/clamping (e.g. itemsPerPage silently caps at 100 rather
            // than 422ing, AC-3.5) rather than relying on API Platform's own query-parameter schema
            // validation, which would reject an out-of-range value instead of capping it.
            queryParameterValidationEnabled: false,
            parameters: [
                'status' => new QueryParameter(
                    key: 'status',
                    schema: ['type' => 'string', 'enum' => ['upcoming', 'past']],
                    description: 'Filter by upcoming/past (D-24). Omit to return both. An unrecognised value is a 422 (AC-3.3).',
                ),
                'band' => new QueryParameter(
                    key: 'band',
                    schema: ['type' => 'string'],
                    description: 'Filter to concerts whose lineup contains a band matching this normalized substring (US-4, AC-4.2).',
                ),
                'order[date]' => new QueryParameter(
                    key: 'order[date]',
                    schema: ['type' => 'string', 'enum' => ['asc', 'desc']],
                    description: 'Sort by date. Default: ascending for status=upcoming, descending otherwise (AC-3.4).',
                ),
                'page' => new QueryParameter(
                    key: 'page',
                    schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    description: 'Page number.',
                ),
                'itemsPerPage' => new QueryParameter(
                    key: 'itemsPerPage',
                    schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    description: 'Page size, capped at 100 (D-31, AC-3.5).',
                ),
            ],
        ),
        new Get(
            uriTemplate: '/concerts/{id}',
            output: ConcertOutput::class,
            provider: ConcertItemProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new Patch(
            uriTemplate: '/concerts/{id}',
            // No entity resource to read (D-29) — App\State\ConcertLocator (via ConcertUpdateProcessor)
            // does the owner-filtered lookup itself; the built-in Read stage would 404 on the
            // non-entity resource class before the processor ever ran.
            read: false,
            input: ConcertPatchInput::class,
            output: ConcertOutput::class,
            processor: ConcertUpdateProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new Delete(
            uriTemplate: '/concerts/{id}',
            read: false,
            output: false,
            processor: ConcertDeleteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final class ConcertResource
{
}
