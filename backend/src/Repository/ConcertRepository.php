<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Concert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Concert>
 */
final class ConcertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Concert::class);
    }

    public function createConcertQueryBuilder(string $alias = 'c'): QueryBuilder
    {
        return $this->createQueryBuilder($alias);
    }

    public function save(Concert $concert): void
    {
        $em = $this->getEntityManager();
        $em->persist($concert);
        $em->flush();
    }

    public function remove(Concert $concert): void
    {
        $em = $this->getEntityManager();
        $em->remove($concert);
        $em->flush();
    }
}
