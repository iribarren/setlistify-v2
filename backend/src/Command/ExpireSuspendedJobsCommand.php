<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PlaylistGenerationJobRepository;
use App\Service\Playlist\JobStateMachine;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * T-17 (spec 13 §1, P-4): nightly, moves `awaiting_setlist_choice`/`awaiting_version_choice` jobs
 * past their TTL to `expired`. Fast mode never suspends, so this has no runtime effect in this
 * feature — it ships now because the columns, the partial index and this sweeper are cheaper to
 * build once than to retrofit for prompt 17.
 */
#[AsCommand(
    name: 'app:playlist:expire-jobs',
    description: 'Expires suspended playlist-generation jobs (awaiting_setlist_choice / awaiting_version_choice) past their TTL.',
)]
final class ExpireSuspendedJobsCommand extends Command
{
    public function __construct(
        private readonly PlaylistGenerationJobRepository $repository,
        private readonly JobStateMachine $stateMachine,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $jobs = $this->repository->findExpiredSuspended($now);
        foreach ($jobs as $job) {
            $this->stateMachine->expire($job);
        }

        $io->success(\sprintf('Expired %d suspended job(s).', \count($jobs)));

        return Command::SUCCESS;
    }
}
