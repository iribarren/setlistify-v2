<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\PlaylistRepository;
use App\Security\Voter\PlaylistVoter;
use App\State\PlaylistLocator;

/**
 * `DELETE /api/playlists/{id}` (D-151). Deletes OUR `Playlist` and `PlaylistTrack` rows only — the
 * port has no delete method and D-71 keeps it frozen at nine, so the provider-side playlist is left
 * exactly as it was. Deleting this row does not delete the `PlaylistGenerationJob` (the metrics
 * survive); prompt 16's confirmation copy is where the user is told this stays true.
 *
 * @implements ProcessorInterface<mixed, void>
 */
final readonly class PlaylistDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistLocator $locator,
        private PlaylistRepository $repository,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $playlist = $this->locator->locate($uriVariables['id'] ?? null, PlaylistVoter::DELETE);

        $this->repository->remove($playlist);
    }
}
