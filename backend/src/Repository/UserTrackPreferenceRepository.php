<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserTrackPreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserTrackPreference>
 */
final class UserTrackPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTrackPreference::class);
    }

    /** D-198's key, plus `owner` — the exact uniqueness constraint. */
    public function findOneByKey(User $owner, string $provider, int $algorithmVersion, string $normalizedArtist, string $normalizedTitle): ?UserTrackPreference
    {
        $preference = $this->createQueryBuilder('p')
            ->andWhere('p.owner = :owner')
            ->andWhere('p.provider = :provider')
            ->andWhere('p.algorithmVersion = :algorithmVersion')
            ->andWhere('p.normalizedArtist = :normalizedArtist')
            ->andWhere('p.normalizedTitle = :normalizedTitle')
            ->setParameter('owner', $owner)
            ->setParameter('provider', $provider)
            ->setParameter('algorithmVersion', $algorithmVersion)
            ->setParameter('normalizedArtist', $normalizedArtist)
            ->setParameter('normalizedTitle', $normalizedTitle)
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $preference instanceof UserTrackPreference ? $preference : null;
    }

    public function save(UserTrackPreference $preference): void
    {
        $em = $this->getEntityManager();
        $em->persist($preference);
        $em->flush();
    }
}
