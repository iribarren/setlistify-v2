<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\JobStateMachine;
use App\State\PlaylistGenerationJobLocator;
use App\State\PlaylistGenerationJobOutputMapper;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /api/playlist-generation-jobs/{id}/cancel` (T-18, spec 14 §6).
 *
 * @implements ProcessorInterface<mixed, PlaylistGenerationJobOutput>
 */
final readonly class CancelGenerationProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
        private JobStateMachine $stateMachine,
        private PlaylistGenerationJobOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::MANAGE);

        if ($job->getState()->isTerminal()) {
            throw new UnprocessableEntityHttpException('This job is already terminal.');
        }

        $this->stateMachine->cancel($job);

        return $this->mapper->map($job);
    }
}
