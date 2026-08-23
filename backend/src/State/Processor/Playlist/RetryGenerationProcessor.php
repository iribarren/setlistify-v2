<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\Message\BuildPlaylistMessage;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobState;
use App\State\PlaylistGenerationJobLocator;
use App\State\PlaylistGenerationJobOutputMapper;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/playlist-generation-jobs/{id}/retry` (T-16, spec 14 §6). Only legal from `failed` —
 * re-enters the SAME row with `attempt++` and the same `idempotencyKey`, which is what makes the
 * three idempotency levels (spec 14 §5) hold across the retry.
 *
 * @implements ProcessorInterface<mixed, PlaylistGenerationJobOutput>
 */
final readonly class RetryGenerationProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
        private JobStateMachine $stateMachine,
        private PlaylistGenerationJobOutputMapper $mapper,
        private MessageBusInterface $messageBus,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::MANAGE);

        if (JobState::Failed !== $job->getState()) {
            throw new UnprocessableEntityHttpException('Only a failed job can be retried.');
        }

        $this->stateMachine->retry($job);
        $this->messageBus->dispatch(new BuildPlaylistMessage($job->getId() ?? 0, $job->getAttempt()));

        return $this->mapper->map($job);
    }
}
