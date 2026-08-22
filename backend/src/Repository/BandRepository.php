<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\ConcertBand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Band>
 */
final class BandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Band::class);
    }

    public function findOneByNormalizedName(string $normalizedName): ?Band
    {
        return $this->findOneBy(['normalizedName' => $normalizedName]);
    }

    /**
     * AC-10.1, AC-10.4 (`app:setlist:refresh`): bands attached to a concert that is upcoming, or
     * that ended within `$windowStart`..now, nearest-to-today first — upcoming concerts (soonest
     * first) ahead of recently-past ones (most recent first), each band counted once.
     *
     * @return list<Band>
     */
    public function findPrioritizedForRefresh(\DateTimeImmutable $now, \DateTimeImmutable $windowStart, int $limit): array
    {
        /** @var list<Band> $upcoming */
        $upcoming = $this->refreshCandidatesQueryBuilder()
            ->andWhere('c.pastAfter > :now')
            ->setParameter('now', $now)
            ->addOrderBy('c.date', 'ASC')
            ->getQuery()
            ->getResult();

        /** @var list<Band> $recentPast */
        $recentPast = $this->refreshCandidatesQueryBuilder()
            ->andWhere('c.pastAfter <= :now')
            ->andWhere('c.pastAfter > :windowStart')
            ->setParameter('now', $now)
            ->setParameter('windowStart', $windowStart)
            ->addOrderBy('c.date', 'DESC')
            ->getQuery()
            ->getResult();

        $seen = [];
        $ordered = [];
        foreach ([...$upcoming, ...$recentPast] as $band) {
            $id = $band->getId();
            if (null === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ordered[] = $band;
            if (\count($ordered) >= $limit) {
                break;
            }
        }

        return $ordered;
    }

    private function refreshCandidatesQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from(Band::class, 'b')
            ->join(ConcertBand::class, 'cb', 'WITH', 'cb.band = b')
            ->join(Concert::class, 'c', 'WITH', 'cb.concert = c');
    }
}
