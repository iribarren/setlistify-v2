<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\ApiResource\Playlist\SetlistChoiceInput;
use App\ApiResource\Playlist\SetlistChoiceItemInput;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\Choice\SetlistChoiceApplier;
use App\State\PlaylistGenerationJobLocator;
use App\State\PlaylistGenerationJobOutputMapper;

/**
 * `POST /api/playlist-generation-jobs/{id}/setlist-choice` (T-05, docs/specs/
 * 2026-08-25-playlist-normal-mode.md).
 *
 * @implements ProcessorInterface<SetlistChoiceInput, PlaylistGenerationJobOutput>
 */
final readonly class SubmitSetlistChoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
        private SetlistChoiceApplier $applier,
        private PlaylistGenerationJobOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::MANAGE);

        $choices = array_map(static fn (SetlistChoiceItemInput $item): array => [
            'bandId' => $item->bandId ?? 0,
            'setlistfmId' => $item->setlistfmId ?? '',
        ], $data->choices);

        $this->applier->apply($job, $choices);

        return $this->mapper->map($job);
    }
}
