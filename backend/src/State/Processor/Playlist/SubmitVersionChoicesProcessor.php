<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\ApiResource\Playlist\VersionChoiceItemInput;
use App\ApiResource\Playlist\VersionChoicesInput;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\Choice\VersionChoiceApplier;
use App\State\PlaylistGenerationJobLocator;
use App\State\PlaylistGenerationJobOutputMapper;

/**
 * `POST /api/playlist-generation-jobs/{id}/version-choices` (T-08, docs/specs/
 * 2026-08-25-playlist-normal-mode.md). D-192: a full replacement, idempotent while still
 * `awaiting_version_choice`.
 *
 * @implements ProcessorInterface<VersionChoicesInput, PlaylistGenerationJobOutput>
 */
final readonly class SubmitVersionChoicesProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
        private VersionChoiceApplier $applier,
        private PlaylistGenerationJobOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::MANAGE);

        $choices = array_map(static fn (VersionChoiceItemInput $item): array => [
            'sourcePosition' => $item->sourcePosition ?? 0,
            'segmentIndex' => $item->segmentIndex,
            'providerTrackId' => $item->providerTrackId,
        ], $data->choices);

        $this->applier->apply($job, $choices);

        return $this->mapper->map($job);
    }
}
