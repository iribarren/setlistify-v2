<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConcertBand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConcertBand>
 */
final class ConcertBandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConcertBand::class);
    }
}
