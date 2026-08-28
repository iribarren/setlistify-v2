<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\ConcertBand;
use App\Repository\ConcertRepository;
use App\Security\ConcertOwnerExtension;

/**
 * "This band is on one of my concerts" (docs/specs/2026-08-27-instant-setlist-refresh.md, D-266,
 * AC-1.4). Reuses `ConcertOwnerExtension`'s owner-filtered query path exactly as `ConcertLocator`
 * does — no new query extension is introduced, and `ConcertOwnerExtension` itself is not modified.
 */
final readonly class BandOwnershipChecker
{
    public function __construct(
        private ConcertRepository $concertRepository,
        private ConcertOwnerExtension $ownerExtension,
    ) {
    }

    public function ownsAConcertFeaturing(Band $band): bool
    {
        $queryBuilder = $this->concertRepository->createConcertQueryBuilder('c')
            ->join(ConcertBand::class, 'cb', 'WITH', 'cb.concert = c')
            ->andWhere('cb.band = :ownership_band')
            ->setParameter('ownership_band', $band)
            ->setMaxResults(1);

        $this->ownerExtension->applyToCollection($queryBuilder, new QueryNameGenerator(), Concert::class);

        return null !== $queryBuilder->getQuery()->getOneOrNullResult();
    }
}
