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

    private const array LIVE_STATES = [
        'queued', 'resolving_setlist', 'awaiting_setlist_choice',
        'matching', 'awaiting_version_choice', 'building', 'blocked',
    ];

    /** Level-1 idempotency support (D-129) — the same query the `uniq_live_generation` index protects. */
    public function findLiveJob(int $concertId, string $providerKey): ?PlaylistGenerationJob
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.concert = :concertId')
            ->andWhere('j.providerKey = :providerKey')
            ->andWhere('j.state IN (:states)')
            ->setParameter('concertId', $concertId)
            ->setParameter('providerKey', $providerKey)
            ->setParameter('states', self::LIVE_STATES)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /** `app:playlist:resume-blocked` (T-13). */
    public function findResumableBlocked(\DateTimeImmutable $now, int $limit = 100): array
    {
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

    /** `app:playlist:expire-jobs` (T-17). */
    public function findExpiredSuspended(\DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('j')
            ->andWhere('j.state IN (:states)')
            ->andWhere('j.expiresAt IS NOT NULL')
            ->andWhere('j.expiresAt <= :now')
            ->setParameter('states', ['awaiting_setlist_choice', 'awaiting_version_choice'])
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
