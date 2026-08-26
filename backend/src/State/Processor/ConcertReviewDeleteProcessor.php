<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ConcertReview;
use App\Repository\ConcertReviewRepository;
use App\Security\ConcertReviewOwnerExtension;
use App\Security\Voter\ConcertVoter;
use App\State\ConcertLocator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `DELETE /api/concerts/{concertId}/review` (US-2, AC-2.3). **Never blocked by the past-only rule**
 * (D-235) — a user must always be able to remove their own words, regardless of whether the
 * concert's date has since moved into the future. `404`, not `204`, when no review exists (AC-2.4) —
 * consistent with the singleton `GET`.
 *
 * @implements ProcessorInterface<null, void>
 */
final readonly class ConcertReviewDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private ConcertLocator $concertLocator,
        private ConcertReviewRepository $reviewRepository,
        private ConcertReviewOwnerExtension $ownerExtension,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $concert = $this->concertLocator->locate($uriVariables['concertId'] ?? null, ConcertVoter::DELETE);

        $queryBuilder = $this->reviewRepository->createConcertReviewQueryBuilder('r');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), ConcertReview::class, []);
        $queryBuilder->andWhere('r.concert = :concert')->setParameter('concert', $concert);

        $review = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$review instanceof ConcertReview) {
            throw new NotFoundHttpException();
        }

        $this->reviewRepository->remove($review);
    }
}
