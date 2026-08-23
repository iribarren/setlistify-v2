<?php

declare(strict_types=1);

namespace App\State\Provider\Playlist;

use ApiPlatform\Doctrine\Orm\Paginator as OrmPaginator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\Entity\PlaylistGenerationJob;
use App\Repository\PlaylistGenerationJobRepository;
use App\Security\PlaylistGenerationJobOwnerExtension;
use App\State\Pagination\MappingPaginator;
use App\State\PlaylistGenerationJobOutputMapper;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/playlist-generation-jobs` (spec 14 §6), filtered by `?concertId=&state=`, owner-scoped.
 *
 * @implements ProviderInterface<MappingPaginator<object, PlaylistGenerationJobOutput>>
 */
final readonly class PlaylistGenerationJobCollectionProvider implements ProviderInterface
{
    private const int ITEMS_PER_PAGE = 20;

    public function __construct(
        private PlaylistGenerationJobRepository $repository,
        private PlaylistGenerationJobOwnerExtension $ownerExtension,
        private PlaylistGenerationJobOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MappingPaginator
    {
        /** @var Request $request */
        $request = $context['request'];

        $queryBuilder = $this->repository->createQueryBuilder('j');
        $this->ownerExtension->applyToCollection($queryBuilder, new QueryNameGenerator(), PlaylistGenerationJob::class, $operation);

        $concertId = $request->query->get('concertId');
        if (is_numeric($concertId)) {
            $queryBuilder->andWhere('j.concert = :concertId')->setParameter('concertId', (int) $concertId);
        }

        $state = $request->query->get('state');
        if (\is_string($state) && '' !== $state) {
            $queryBuilder->andWhere('j.state = :state')->setParameter('state', $state);
        }

        $page = max(1, $request->query->getInt('page', 1));

        $queryBuilder
            ->addOrderBy('j.createdAt', 'DESC')
            ->addOrderBy('j.id', 'DESC')
            ->setFirstResult(($page - 1) * self::ITEMS_PER_PAGE)
            ->setMaxResults(self::ITEMS_PER_PAGE);

        $ormPaginator = new OrmPaginator(new DoctrinePaginator($queryBuilder->getQuery(), false));

        return new MappingPaginator($ormPaginator, function (object $job): PlaylistGenerationJobOutput {
            \assert($job instanceof PlaylistGenerationJob);

            return $this->mapper->map($job);
        });
    }
}
