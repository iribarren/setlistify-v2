<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ConcertOutput;
use App\Entity\ConcertReview;
use App\Repository\ConcertReviewRepository;
use App\Security\ConcertReviewOwnerExtension;
use App\Security\Voter\ConcertVoter;
use App\State\ConcertLocator;
use App\State\ConcertOutputMapper;
use App\State\ConcertReviewOutputMapper;

/**
 * `GET /api/concerts/{id}` (US-3 item form, US-7). Ownership is enforced twice — see
 * `App\State\ConcertLocator`. `reviewSummary` (D-241) is a single extra lookup — no N+1 concern for
 * an item provider, since there is exactly one item.
 *
 * @implements ProviderInterface<ConcertOutput>
 */
final readonly class ConcertItemProvider implements ProviderInterface
{
    public function __construct(
        private ConcertLocator $locator,
        private ConcertOutputMapper $mapper,
        private ConcertReviewRepository $reviewRepository,
        private ConcertReviewOwnerExtension $reviewOwnerExtension,
        private ConcertReviewOutputMapper $reviewMapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ConcertOutput
    {
        $concert = $this->locator->locate($uriVariables['id'] ?? null, ConcertVoter::VIEW);

        $queryBuilder = $this->reviewRepository->createConcertReviewQueryBuilder('r');
        $this->reviewOwnerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), ConcertReview::class, []);
        $queryBuilder->andWhere('r.concert = :concert')->setParameter('concert', $concert);
        $review = $queryBuilder->getQuery()->getOneOrNullResult();

        $reviewSummary = $review instanceof ConcertReview ? $this->reviewMapper->mapSummary($review) : null;

        return $this->mapper->map($concert, $reviewSummary);
    }
}
