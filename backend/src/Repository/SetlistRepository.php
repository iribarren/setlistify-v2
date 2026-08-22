<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Band;
use App\Entity\Setlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setlist>
 */
final class SetlistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setlist::class);
    }

    public function findOneBySetlistfmId(string $setlistfmId): ?Setlist
    {
        return $this->findOneBy(['setlistfmId' => $setlistfmId]);
    }

    /**
     * AC-3.1, AC-3.5: the API's own pagination is a normal SQL query over what is already cached —
     * never proxied page-by-page to setlist.fm.
     */
    public function createBandSetlistsQueryBuilder(Band $band): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.band = :band')
            ->setParameter('band', $band)
            ->addOrderBy('s.eventDate', 'DESC')
            ->addOrderBy('s.id', 'DESC');
    }

    public function countForBand(Band $band): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.band = :band')
            ->setParameter('band', $band)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Setlist $setlist): void
    {
        $em = $this->getEntityManager();
        $em->persist($setlist);
        $em->flush();
    }

    /** AC-11.5: clearing a band's cached setlist associations after an MBID correction. */
    public function deleteAllForBand(Band $band): int
    {
        $affected = $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.band = :band')
            ->setParameter('band', $band)
            ->getQuery()
            ->execute();

        return \is_int($affected) ? $affected : 0;
    }
}
