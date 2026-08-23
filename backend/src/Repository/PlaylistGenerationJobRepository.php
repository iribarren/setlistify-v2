<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistGenerationJob>
 */
final class PlaylistGenerationJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistGenerationJob::class);
    }

    /**
     * The states `uniq_live_generation` treats as live. Derived from the enum rather than written as
     * literals, so a new state cannot be added to `JobState` and silently missed here.
     *
     * @return list<string>
     */
    private static function liveStates(): array
    {
        $terminal = [JobState::Completed, JobState::Failed, JobState::Expired, JobState::Cancelled];

        $live = [];
        foreach (JobState::cases() as $state) {
            if (!\in_array($state, $terminal, true)) {
                $live[] = $state->value;
            }
        }

        return $live;
    }

    /** Level-1 idempotency support (D-129) — the same query the `uniq_live_generation` index protects. */
    public function findLiveJob(int $concertId, string $providerKey): ?PlaylistGenerationJob
    {
        $job = $this->createQueryBuilder('j')
            ->andWhere('j.concert = :concertId')
            ->andWhere('j.providerKey = :providerKey')
            ->andWhere('j.state IN (:states)')
            ->setParameter('concertId', $concertId)
            ->setParameter('providerKey', $providerKey)
            ->setParameter('states', self::liveStates())
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $job instanceof PlaylistGenerationJob ? $job : null;
    }

    /**
     * `app:playlist:resume-blocked` (T-13).
     *
     * @return list<PlaylistGenerationJob>
     */
    public function findResumableBlocked(\DateTimeImmutable $now, int $limit = 100): array
    {
        /** @var list<PlaylistGenerationJob> */
        return $this->createQueryBuilder('j')
            ->andWhere('j.state = :state')
            ->andWhere('j.resumableAfter IS NOT NULL')
            ->andWhere('j.resumableAfter <= :now')
            ->setParameter('state', JobState::Blocked->value)
            ->setParameter('now', $now)
            ->getQuery()
            ->setMaxResults($limit)
            ->getResult();
    }

    /**
     * `app:playlist:expire-jobs` (T-17).
     *
     * @return list<PlaylistGenerationJob>
     */
    public function findExpiredSuspended(\DateTimeImmutable $now, int $limit = 200): array
    {
        /** @var list<PlaylistGenerationJob> */
        return $this->createQueryBuilder('j')
            ->andWhere('j.state IN (:states)')
            ->andWhere('j.expiresAt IS NOT NULL')
            ->andWhere('j.expiresAt <= :now')
            ->setParameter('states', [
                JobState::AwaitingSetlistChoice->value,
                JobState::AwaitingVersionChoice->value,
            ])
            ->setParameter('now', $now)
            ->getQuery()
            ->setMaxResults($limit)
            ->getResult();
    }

    public function save(PlaylistGenerationJob $job): void
    {
        $em = $this->getEntityManager();
        $em->persist($job);
        $em->flush();
    }
}
