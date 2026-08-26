<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ConcertReviewOutput;
use App\Entity\ConcertReview;
use App\Repository\ConcertReviewRepository;
use App\Security\ConcertReviewOwnerExtension;
use App\Security\Voter\ConcertVoter;
use App\State\ConcertLocator;
use App\State\ConcertReviewOutputMapper;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/concerts/{concertId}/review` (US-2, US-3). Ownership is enforced twice (D-229): the
 * parent `Concert` is resolved through `ConcertLocator` first — a non-owner's `concertId` 404s
 * before `concert_reviews` is ever queried — then `ConcertReviewOwnerExtension` applies
 * `WHERE owner = :current_user` as the second gate.
 *
 * @implements ProviderInterface<ConcertReviewOutput>
 */
final readonly class ConcertReviewProvider implements ProviderInterface
{
    public function __construct(
        private ConcertLocator $concertLocator,
        private ConcertReviewRepository $reviewRepository,
        private ConcertReviewOwnerExtension $ownerExtension,
        private ConcertReviewOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ConcertReviewOutput
    {
        $concert = $this->concertLocator->locate($uriVariables['concertId'] ?? null, ConcertVoter::VIEW);

        $queryBuilder = $this->reviewRepository->createConcertReviewQueryBuilder('r');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), ConcertReview::class, []);
        $queryBuilder->andWhere('r.concert = :concert')->setParameter('concert', $concert);

        $review = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$review instanceof ConcertReview) {
            throw new NotFoundHttpException();
        }

        return $this->mapper->map($review);
    }
}
