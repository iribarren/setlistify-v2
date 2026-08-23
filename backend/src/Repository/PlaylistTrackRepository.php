<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlaylistTrack;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistTrack>
 */
final class PlaylistTrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistTrack::class);
    }
}
