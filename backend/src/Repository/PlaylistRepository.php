<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Playlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
final class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    public function save(Playlist $playlist): void
    {
        $em = $this->getEntityManager();
        $em->persist($playlist);
        $em->flush();
    }

    /** D-151: deletes our row only — never the provider-side playlist (the port has no delete method). */
    public function remove(Playlist $playlist): void
    {
        $em = $this->getEntityManager();
        $em->remove($playlist);
        $em->flush();
    }
}
