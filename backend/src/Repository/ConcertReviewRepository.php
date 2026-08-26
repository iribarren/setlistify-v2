<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConcertReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConcertReview>
 */
final class ConcertReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConcertReview::class);
    }

    public function createConcertReviewQueryBuilder(string $alias = 'r'): QueryBuilder
    {
        return $this->createQueryBuilder($alias);
    }

    public function save(ConcertReview $review): void
    {
        $em = $this->getEntityManager();
        $em->persist($review);
        $em->flush();
    }

    public function remove(ConcertReview $review): void
    {
        $em = $this->getEntityManager();
        $em->remove($review);
        $em->flush();
    }
}
