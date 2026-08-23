<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\Processor\Playlist\PlaylistDeleteProcessor;
use App\State\Provider\Playlist\PlaylistCollectionProvider;
use App\State\Provider\Playlist\PlaylistItemProvider;

/**
 * `/api/playlists` (spec 14 §6) — the generated result of a `PlaylistGenerationJob`. No entity
 * binding (D-29); read-only except for delete.
 */
#[ApiResource(
    shortName: 'Playlist',
    description: 'A generated playlist for a concert, with its per-song report (US-1, US-2).',
    operations: [
        new Get(
            uriTemplate: '/playlists/{id}',
            output: PlaylistOutput::class,
            provider: PlaylistItemProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
        ),
        new GetCollection(
            uriTemplate: '/playlists',
            output: PlaylistOutput::class,
            provider: PlaylistCollectionProvider::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            queryParameterValidationEnabled: false,
            parameters: [
                'concertId' => new QueryParameter(key: 'concertId', schema: ['type' => 'integer'], description: 'Filter to playlists for one concert.'),
                'page' => new QueryParameter(key: 'page', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            ],
        ),
        new Delete(
            uriTemplate: '/playlists/{id}',
            read: false,
            output: false,
            processor: PlaylistDeleteProcessor::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            description: 'Removes this playlist and its tracks from Setlistify only (D-151). The port has no delete method (frozen at nine, D-71) — the playlist stays in the user\'s provider account until they delete it there themselves. The owning PlaylistGenerationJob is not deleted.',
        ),
    ],
)]
final class PlaylistResource
{
}
