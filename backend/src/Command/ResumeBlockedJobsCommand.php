<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\BuildPlaylistMessage;
use App\Repository\PlaylistGenerationJobRepository;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\FailureReason;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * T-13/T-14 (spec 13 §1, spec 14 §9): every 5 minutes, finds `blocked` jobs whose `resumableAfter`
 * has passed and either re-queues them (`blockCycleCount <= MAX_BLOCK_CYCLES`) or gives up
 * (`failed`, `block_cycles_exhausted`) — "the world is not coming back on its own".
 */
#[AsCommand(
    name: 'app:playlist:resume-blocked',
    description: 'Resumes blocked playlist-generation jobs whose resumableAfter has passed, or fails them past MAX_BLOCK_CYCLES.',
)]
final class ResumeBlockedJobsCommand extends Command
{
    public function __construct(
        private readonly PlaylistGenerationJobRepository $repository,
        private readonly JobStateMachine $stateMachine,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
        private readonly int $maxBlockCycles,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $jobs = $this->repository->findResumableBlocked($now);
        $resumed = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            if ($job->getBlockCycleCount() > $this->maxBlockCycles) {
                $this->stateMachine->fail($job, FailureReason::BlockCyclesExhausted, [
                    'blockCycleCount' => $job->getBlockCycleCount(),
                ]);
                ++$failed;

                continue;
            }

            $this->stateMachine->resume($job);
            $this->messageBus->dispatch(new BuildPlaylistMessage($job->getId() ?? 0, $job->getAttempt()));
            ++$resumed;
        }

        $io->success(\sprintf('Resumed %d job(s), failed %d job(s) past MAX_BLOCK_CYCLES.', $resumed, $failed));

        return Command::SUCCESS;
    }
}
