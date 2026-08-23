<?php

declare(strict_types=1);

namespace App\State\Provider\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Playlist\PlaylistOutput;
use App\Security\Voter\PlaylistVoter;
use App\State\PlaylistLocator;
use App\State\PlaylistOutputMapper;

/**
 * `GET /api/playlists/{id}` (spec 14 §6).
 *
 * @implements ProviderInterface<PlaylistOutput>
 */
final readonly class PlaylistItemProvider implements ProviderInterface
{
    public function __construct(
        private PlaylistLocator $locator,
        private PlaylistOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlaylistOutput
    {
        $playlist = $this->locator->locate($uriVariables['id'] ?? null, PlaylistVoter::VIEW);

        return $this->mapper->map($playlist);
    }
}
