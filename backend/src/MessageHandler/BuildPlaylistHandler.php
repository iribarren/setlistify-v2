<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\PlaylistGenerationJob;
use App\Message\BuildPlaylistMessage;
use App\Repository\PlaylistGenerationJobRepository;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\PlaylistPipeline;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Five steps, in order (spec 14 §3): acquire the job lock, re-read the row inside it, delegate to
 * `PlaylistPipeline::run()`, catch `GenerationBlockedException` (acknowledged, never retried — a
 * blocked job is resumed by the sweeper or a user action), and let any other `\Throwable` propagate
 * so Messenger's own retry policy applies (F-12). Contains no business logic of its own.
 */
#[AsMessageHandler]
final readonly class BuildPlaylistHandler
{
    private const string LOCK_RESOURCE_PREFIX = 'playlist-job-';
    private const float LOCK_TTL_SECONDS = 300.0;

    public function __construct(
        private PlaylistGenerationJobRepository $repository,
        private PlaylistPipeline $pipeline,
        private JobStateMachine $stateMachine,
        private LockFactory $lockFactory,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BuildPlaylistMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_RESOURCE_PREFIX.$message->jobId, self::LOCK_TTL_SECONDS);

        if (!$lock->acquire(false)) {
            // T-20: a run is already in flight for this job — the redelivery is a no-op.
            return;
        }

        try {
            $job = $this->repository->find($message->jobId);
            if (!$job instanceof PlaylistGenerationJob) {
                return; // Deleted (cascaded from a removed Concert) — nothing to do.
            }

            $this->entityManager->refresh($job);

            if (!$job->getState()->isActive()) {
                // Stale message: a suspended, blocked or terminal job is not this handler's
                // business — the sweeper or a user action moves those, never a redelivery.
                return;
            }

            try {
                $this->pipeline->run($job);
            } catch (GenerationBlockedException $e) {
                $this->stateMachine->block($job, $e->reason, $e->resumableAfter, $e->stage);
                $this->logger->info('Playlist generation blocked.', [
                    'jobId' => $job->getId(),
                    'reason' => $e->reason->value,
                ]);
            }
        } finally {
            $lock->release();
        }
    }
}
