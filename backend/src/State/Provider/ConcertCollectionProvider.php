<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\Paginator as OrmPaginator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Concert;
use App\Filter\ConcertBandNameFilter;
use App\Filter\ConcertStatusFilter;
use App\Repository\ConcertRepository;
use App\Security\ConcertOwnerExtension;
use App\State\ConcertOutputMapper;
use App\State\Pagination\MappingPaginator;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /api/concerts` (US-3, US-4, US-7). Ownership (D-27), `status` (D-24), `band` (US-4) and
 * pagination (AC-3.5) are all applied here, on one Doctrine ORM `QueryBuilder`, so the status filter
 * stays index-backed (AC-3.7) and pagination stays a real SQL `LIMIT`/`OFFSET` rather than an
 * in-memory slice — see `App\State\Pagination\MappingPaginator`.
 *
 * @implements ProviderInterface<MappingPaginator<object, \App\ApiResource\ConcertOutput>>
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
        private ConcertOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MappingPaginator
    {
        /** @var Request $request */
        $request = $context['request'];

        $status = $this->stringParam($request, 'status');
        $band = $this->stringParam($request, 'band');
        $orderDirection = $this->orderDirection($request, $status);
        [$page, $itemsPerPage] = $this->pagination($request);

        $queryBuilder = $this->concertRepository->createConcertQueryBuilder('c');
        $this->ownerExtension->applyToCollection($queryBuilder, new QueryNameGenerator(), Concert::class, $operation);
        $this->statusFilter->apply($queryBuilder, 'c', $status);
        $this->bandNameFilter->apply($queryBuilder, 'c', $band);

        $queryBuilder
            ->addOrderBy('c.date', $orderDirection)
            ->addOrderBy('c.id', $orderDirection)
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        $ormPaginator = new OrmPaginator(new DoctrinePaginator($queryBuilder->getQuery(), true));

        // ApiPlatform\Doctrine\Orm\Paginator isn't itself generic, so PHPStan only knows it yields
        // `object` — the QueryBuilder's own root entity (Concert) guarantees the real runtime type.
        return new MappingPaginator($ormPaginator, function (object $concert): \App\ApiResource\ConcertOutput {
            \assert($concert instanceof Concert);

            return $this->mapper->map($concert);
        });
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
