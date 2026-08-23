<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use App\Entity\Playlist;
use App\Repository\PlaylistRepository;
use App\Security\PlaylistOwnerExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Copies `App\State\ConcertLocator`'s shape (D-157) for `Playlist`: owner-filtered lookup, then the
 * voter as the second gate. Both "no such id" and "someone else's id" reach the exact same
 * `NotFoundHttpException`.
 */
final readonly class PlaylistLocator
{
    public function __construct(
        private PlaylistRepository $repository,
        private PlaylistOwnerExtension $ownerExtension,
        private Security $security,
    ) {
    }

    public function locate(mixed $id, string $voterAttribute): Playlist
    {
        if (!is_numeric($id)) {
            throw new NotFoundHttpException();
        }

        $intId = (int) $id;

        $queryBuilder = $this->repository->createQueryBuilder('p');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), Playlist::class, ['id' => $intId]);
        $queryBuilder->andWhere('p.id = :playlist_id')->setParameter('playlist_id', $intId);

        $playlist = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$playlist instanceof Playlist) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted($voterAttribute, $playlist)) {
            throw new AccessDeniedHttpException();
        }

        return $playlist;
    }
}
