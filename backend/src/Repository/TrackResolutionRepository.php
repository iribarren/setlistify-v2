<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrackResolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackResolution>
 */
final class TrackResolutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackResolution::class);
    }

    public function findOneByKey(string $provider, int $algorithmVersion, string $normalizedArtist, string $normalizedTitle): ?TrackResolution
    {
        return $this->findOneBy([
            'provider' => $provider,
            'algorithmVersion' => $algorithmVersion,
            'normalizedArtist' => $normalizedArtist,
            'normalizedTitle' => $normalizedTitle,
        ]);
    }

    public function save(TrackResolution $resolution): void
    {
        $em = $this->getEntityManager();
        $em->persist($resolution);
        $em->flush();
    }

    public function delete(TrackResolution $resolution): void
    {
        $em = $this->getEntityManager();
        $em->remove($resolution);
        $em->flush();
    }

    /** `app:playlist:expire-jobs` also prunes resolution rows more than one algorithm version behind. */
    public function deleteOlderThanVersion(int $currentVersion): int
    {
        $affected = $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.algorithmVersion < :version')
            ->setParameter('version', $currentVersion - 1)
            ->getQuery()
            ->execute();

        return \is_int($affected) ? $affected : 0;
    }
}
