<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\State\Processor\Playlist\CancelGenerationProcessor;
use App\State\Processor\Playlist\CreateAnywayProcessor;
use App\State\Processor\Playlist\RetryGenerationProcessor;
use App\State\Processor\Playlist\StartGenerationProcessor;
use App\State\Provider\Playlist\PlaylistGenerationJobCollectionProvider;
use App\State\Provider\Playlist\PlaylistGenerationJobItemProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/playlist-generation-jobs` (spec 14 §6). No entity binding (D-29) — writes go through
 * `StartGenerationInput`; every other operation has an empty request body. A `blocked` job is always
 * HTTP 200 on `GET`, never an error (spec 14 §6's polling contract).
 */
#[ApiResource(
    shortName: 'PlaylistGenerationJob',
    description: 'One run of the playlist-generation pipeline for a concert (US-1 through US-5).',
    operations: [
        new Post(
            uriTemplate: '/playlist-generation-jobs',
            status: Response::HTTP_CREATED,
            input: StartGenerationInput::class,
            output: PlaylistGenerationJobOutput::class,
            processor: StartGenerationProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: 'Starts (or returns the already-live) playlist generation for a concert. Zero provider and zero setlist.fm calls happen on this request thread (AC-1.1); a second POST for the same live (concert, provider) returns 200 with the existing job, never a 409 (D-129).',
        ),
        new Get(
            uriTemplate: '/playlist-generation-jobs/{id}',
            output: PlaylistGenerationJobOutput::class,
            provider: PlaylistGenerationJobItemProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: 'Poll this while a generation is active. Carries an ETag (a matching If-None-Match returns 304) and a Retry-After header while active; both are absent once terminal, blocked or suspended.',
        ),
        new GetCollection(
            uriTemplate: '/playlist-generation-jobs',
            output: PlaylistGenerationJobOutput::class,
            provider: PlaylistGenerationJobCollectionProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            queryParameterValidationEnabled: false,
            parameters: [
                'concertId' => new QueryParameter(key: 'concertId', schema: ['type' => 'integer'], description: 'Filter to jobs for one concert.'),
                'state' => new QueryParameter(key: 'state', schema: ['type' => 'string'], description: 'Filter to jobs in one state.'),
                'page' => new QueryParameter(key: 'page', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            ],
        ),
        new Post(
            uriTemplate: '/playlist-generation-jobs/{id}/retry',
            status: Response::HTTP_ACCEPTED,
            read: false,
            input: false,
            output: PlaylistGenerationJobOutput::class,
            processor: RetryGenerationProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: 'Retries a failed job. Re-enters the SAME row (T-16) — same idempotency key, attempt+1. 422 unless the job is currently failed.',
        ),
        new Post(
            uriTemplate: '/playlist-generation-jobs/{id}/cancel',
            status: Response::HTTP_ACCEPTED,
            read: false,
            input: false,
            output: PlaylistGenerationJobOutput::class,
            processor: CancelGenerationProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: '422 if the job is already terminal.',
        ),
        new Post(
            uriTemplate: '/playlist-generation-jobs/{id}/create-anyway',
            status: Response::HTTP_ACCEPTED,
            read: false,
            input: false,
            output: PlaylistGenerationJobOutput::class,
            processor: CreateAnywayProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: 'F-14 recovery (P-3): clears the creation marker and re-queues. 422 unless failureReason is creation_indeterminate. Never creates silently.',
        ),
    ],
)]
final class PlaylistGenerationJobResource
{
}
