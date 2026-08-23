<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\Message\BuildPlaylistMessage;
use App\Repository\PlaylistRepository;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\FailureReason;
use App\State\PlaylistGenerationJobLocator;
use App\State\PlaylistGenerationJobOutputMapper;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/playlist-generation-jobs/{id}/create-anyway` (F-14, P-3, spec 14 §6). The one
 * indeterminate case — `creationAttemptedAt` set, `providerPlaylistId` still null — is surfaced to
 * the user rather than closed automatically; this operation clears the marker and re-queues.
 *
 * @implements ProcessorInterface<mixed, PlaylistGenerationJobOutput>
 */
final readonly class CreateAnywayProcessor implements ProcessorInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
        private PlaylistRepository $playlistRepository,
        private JobStateMachine $stateMachine,
        private PlaylistGenerationJobOutputMapper $mapper,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::MANAGE);

        if (FailureReason::CreationIndeterminate !== $job->getFailureReason()) {
            throw new UnprocessableEntityHttpException('This job is not in a creation-indeterminate state.');
        }

        $playlist = $this->playlistRepository->findOneBy(['job' => $job]);
        if (null !== $playlist) {
            $playlist->clearCreationMarker(\DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        $this->stateMachine->retry($job);
        $this->messageBus->dispatch(new BuildPlaylistMessage($job->getId() ?? 0, $job->getAttempt()));

        return $this->mapper->map($job);
    }
}
