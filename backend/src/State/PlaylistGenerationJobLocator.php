<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use App\Entity\PlaylistGenerationJob;
use App\Repository\PlaylistGenerationJobRepository;
use App\Security\PlaylistGenerationJobOwnerExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Copies `App\State\ConcertLocator`'s shape (D-157) for `PlaylistGenerationJob`: owner-filtered
 * lookup, then the voter as the second gate. Both "no such id" and "someone else's id" reach the
 * exact same `NotFoundHttpException`.
 */
final readonly class PlaylistGenerationJobLocator
{
    public function __construct(
        private PlaylistGenerationJobRepository $repository,
        private PlaylistGenerationJobOwnerExtension $ownerExtension,
        private Security $security,
    ) {
    }

    public function locate(mixed $id, string $voterAttribute): PlaylistGenerationJob
    {
        if (!is_numeric($id)) {
            throw new NotFoundHttpException();
        }

        $intId = (int) $id;

        $queryBuilder = $this->repository->createQueryBuilder('j');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), PlaylistGenerationJob::class, ['id' => $intId]);
        $queryBuilder->andWhere('j.id = :job_id')->setParameter('job_id', $intId);

        $job = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$job instanceof PlaylistGenerationJob) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted($voterAttribute, $job)) {
            throw new AccessDeniedHttpException();
        }

        return $job;
    }
}
