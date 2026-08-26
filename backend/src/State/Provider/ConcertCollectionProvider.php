<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\Paginator as OrmPaginator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ConcertReviewSummaryOutput;
use App\Entity\Concert;
use App\Entity\ConcertReview;
use App\Entity\User;
use App\Filter\ConcertBandNameFilter;
use App\Filter\ConcertReviewedFilter;
use App\Filter\ConcertStatusFilter;
use App\Repository\ConcertRepository;
use App\Repository\ConcertReviewRepository;
use App\Security\ConcertOwnerExtension;
use App\State\ConcertOutputMapper;
use App\State\ConcertReviewOutputMapper;
use App\State\Pagination\MappingPaginator;
use App\State\Pagination\MaterializedPaginator;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /api/concerts` (US-3, US-4, US-7). Ownership (D-27), `status` (D-24), `band` (US-4) and
 * pagination (AC-3.5) are all applied here, on one Doctrine ORM `QueryBuilder`, so the status filter
 * stays index-backed (AC-3.7) and pagination stays a real SQL `LIMIT`/`OFFSET` rather than an
 * in-memory slice — see `App\State\Pagination\MappingPaginator`.
 *
 * `reviewSummary` (D-241, AC-6.5) is one extra query for the WHOLE page — never one per concert.
 * The `ConcertReview` `LEFT JOIN` used for `?reviewed=` (AC-6.6) stays a join-for-filtering-only (no
 * field selected from it, so the main query's hydration shape is untouched); the page's own concert
 * ids are then looked up once against `concert_reviews` and matched back in memory — see
 * `App\State\Pagination\MaterializedPaginator` for why this must happen exactly once.
 *
 * @implements ProviderInterface<MappingPaginator<Concert, \App\ApiResource\ConcertOutput>>
 */
final readonly class ConcertCollectionProvider implements ProviderInterface
{
    private const int DEFAULT_ITEMS_PER_PAGE = 20;
    private const int MAX_ITEMS_PER_PAGE = 100;

    public function __construct(
        private ConcertRepository $concertRepository,
        private ConcertOwnerExtension $ownerExtension,
        private ConcertStatusFilter $statusFilter,
        private ConcertBandNameFilter $bandNameFilter,
        private ConcertReviewedFilter $reviewedFilter,
        private ConcertReviewRepository $reviewRepository,
        private ConcertReviewOutputMapper $reviewMapper,
        private ConcertOutputMapper $mapper,
        private Security $security,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MappingPaginator
    {
        /** @var Request $request */
        $request = $context['request'];

        $status = $this->stringParam($request, 'status');
        $band = $this->stringParam($request, 'band');
        $reviewed = $this->stringParam($request, 'reviewed');
        $orderDirection = $this->orderDirection($request, $status);
        [$page, $itemsPerPage] = $this->pagination($request);

        $user = $this->security->getUser();

        $queryBuilder = $this->concertRepository->createConcertQueryBuilder('c');
        $this->ownerExtension->applyToCollection($queryBuilder, new QueryNameGenerator(), Concert::class, $operation);
        $this->statusFilter->apply($queryBuilder, 'c', $status);
        $this->bandNameFilter->apply($queryBuilder, 'c', $band);

        // Join-for-filtering-only: no field of `r` is selected, so this does not change what the
        // main query hydrates (still plain `Concert` entities) — only which rows match.
        if ($user instanceof User) {
            $queryBuilder->leftJoin(ConcertReview::class, 'r', Join::WITH, 'r.concert = c AND r.owner = :review_owner')
                ->setParameter('review_owner', $user);
        } else {
            $queryBuilder->leftJoin(ConcertReview::class, 'r', Join::WITH, '1 = 0');
        }
        $this->reviewedFilter->apply($queryBuilder, 'r', $reviewed);

        $queryBuilder
            ->addOrderBy('c.date', $orderDirection)
            ->addOrderBy('c.id', $orderDirection)
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        $ormPaginator = new OrmPaginator(new DoctrinePaginator($queryBuilder->getQuery(), true));

        // Metadata first (no query), THEN materialize the page exactly once (one query) — see
        // MaterializedPaginator's docblock for why iterating $ormPaginator twice would double it.
        /** @var list<Concert> $concerts */
        $concerts = [];
        foreach ($ormPaginator as $concert) {
            \assert($concert instanceof Concert);
            $concerts[] = $concert;
        }

        $reviewSummaries = $this->fetchReviewSummaries($concerts, $user);

        /** @var MaterializedPaginator<Concert> $materialized */
        $materialized = new MaterializedPaginator($ormPaginator, $concerts);

        return new MappingPaginator($materialized, function (Concert $concert) use ($reviewSummaries): \App\ApiResource\ConcertOutput {
            $concertId = $concert->getId() ?? throw new \LogicException('Concert has no id yet — not persisted.');

            return $this->mapper->map($concert, $reviewSummaries[$concertId] ?? null);
        });
    }

    /**
     * One query for the whole page (AC-6.5) — never one per concert.
     *
     * @param list<Concert> $concerts
     *
     * @return array<int, ConcertReviewSummaryOutput>
     */
    private function fetchReviewSummaries(array $concerts, mixed $user): array
    {
        if ([] === $concerts || !$user instanceof User) {
            return [];
        }

        $queryBuilder = $this->reviewRepository->createConcertReviewQueryBuilder('r');
        $queryBuilder
            ->andWhere('r.owner = :owner')
            ->andWhere('r.concert IN (:concerts)')
            ->setParameter('owner', $user)
            ->setParameter('concerts', $concerts);

        /** @var list<ConcertReview> $reviews */
        $reviews = $queryBuilder->getQuery()->getResult();

        $summaries = [];
        foreach ($reviews as $review) {
            $concertId = $review->getConcert()->getId() ?? throw new \LogicException('Concert has no id yet — not persisted.');
            $summaries[$concertId] = $this->reviewMapper->mapSummary($review);
        }

        return $summaries;
    }

    private function stringParam(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        return \is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function orderDirection(Request $request, ?string $status): string
    {
        $orderParam = $request->query->all('order')['date'] ?? null;

        if (null !== $orderParam) {
            if (!\is_string($orderParam) || !\in_array(strtolower($orderParam), ['asc', 'desc'], true)) {
                throw new UnprocessableEntityHttpException('"order[date]" must be "asc" or "desc".');
            }

            return strtoupper($orderParam);
        }

        // AC-3.4: soonest-first for upcoming, most-recent-first otherwise (including no status filter).
        return 'upcoming' === $status ? 'ASC' : 'DESC';
    }

    /** @return array{int, int} [page, itemsPerPage] */
    private function pagination(Request $request): array
    {
        $page = max(1, $request->query->getInt('page', 1));
        $itemsPerPage = $request->query->getInt('itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE);
        $itemsPerPage = max(1, min($itemsPerPage, self::MAX_ITEMS_PER_PAGE));

        return [$page, $itemsPerPage];
    }
}
