<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Band;
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
}
