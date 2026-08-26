<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\State\Processor\ConcertReviewDeleteProcessor;
use App\State\Processor\ConcertReviewPutProcessor;
use App\State\Provider\ConcertReviewProvider;

/**
 * `/api/concerts/{concertId}/review` — a SINGLETON sub-resource, not a collection (D-228). There is
 * no review id anywhere in the URL and no `POST`: "a second write edits the first" is not a behaviour
 * this class implements, it is the only thing the endpoint can do.
 *
 * Ownership (D-229) is enforced by every provider/processor via `App\State\ConcertLocator` (which
 * resolves the parent `Concert` through `App\Security\ConcertOwnerExtension` FIRST, so a non-owner's
 * `concertId` 404s before `concert_reviews` is ever queried) and then
 * `App\Security\ConcertReviewOwnerExtension` as the second gate — not here;
 * `security: "is_granted('IS_AUTHENTICATED_FULLY')"` only accounts for the 401 case.
 */
#[ApiResource(
    shortName: 'ConcertReview',
    description: "One user's write-up of one concert — rating, notes and an optional highlight (US-1 through US-5).",
    operations: [
        new Get(
            uriTemplate: '/concerts/{concertId}/review',
            uriVariables: ['concertId'],
            output: ConcertReviewOutput::class,
            provider: ConcertReviewProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new Put(
            uriTemplate: '/concerts/{concertId}/review',
            uriVariables: ['concertId'],
            // No entity resource to read (D-29, matching ConcertResource's Patch) — the built-in
            // Read stage would 404 on this non-entity resource class before the processor's own
            // owner-filtered lookup (ConcertLocator) ever ran.
            read: false,
            allowCreate: true,
            input: ConcertReviewInput::class,
            output: ConcertReviewOutput::class,
            processor: ConcertReviewPutProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new Delete(
            uriTemplate: '/concerts/{concertId}/review',
            uriVariables: ['concertId'],
            read: false,
            output: false,
            processor: ConcertReviewDeleteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
    ],
)]
final class ConcertReviewResource
{
}
