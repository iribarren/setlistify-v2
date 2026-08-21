<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use App\Entity\Concert;
use App\Repository\ConcertRepository;
use App\Security\ConcertOwnerExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared by every item operation (`ConcertItemProvider`, `ConcertUpdateProcessor`,
 * `ConcertDeleteProcessor`, US-7): loads a `Concert` by id, pre-filtered to the current owner by
 * `ConcertOwnerExtension` (D-27), then re-checks with `App\Security\Voter\ConcertVoter` as the
 * second gate (AC-7.5). Both "no such id" and "someone else's id" reach the exact same
 * `NotFoundHttpException` — same class, same (absent) message, same RFC 7807 body (AC-7.2, AC-6.4).
 */
final readonly class ConcertLocator
{
    public function __construct(
        private ConcertRepository $concertRepository,
        private ConcertOwnerExtension $ownerExtension,
        private Security $security,
    ) {
    }

    /** @param non-empty-string $voterAttribute one of `ConcertVoter::VIEW`/`EDIT`/`DELETE` */
    public function locate(mixed $id, string $voterAttribute): Concert
    {
        if (!is_numeric($id)) {
            throw new NotFoundHttpException();
        }

        $intId = (int) $id;

        $queryBuilder = $this->concertRepository->createConcertQueryBuilder('c');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), Concert::class, ['id' => $intId]);
        $queryBuilder->andWhere('c.id = :concert_id')->setParameter('concert_id', $intId);

        $concert = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$concert instanceof Concert) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted($voterAttribute, $concert)) {
            // Unreachable today — the query above already filtered to the current owner. Kept as
            // the second gate (D-27) for a future path that reaches a Concert without it.
            throw new AccessDeniedHttpException();
        }

        return $concert;
    }
}
