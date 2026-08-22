<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SetlistCacheEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SetlistCacheEntry>
 */
final class SetlistCacheEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SetlistCacheEntry::class);
    }

    public function findOneByCacheKey(string $cacheKey): ?SetlistCacheEntry
    {
        return $this->findOneBy(['cacheKey' => $cacheKey]);
    }

    public function save(SetlistCacheEntry $entry): void
    {
        $em = $this->getEntityManager();
        $em->persist($entry);
        $em->flush();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
