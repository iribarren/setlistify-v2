<?php

declare(strict_types=1);

namespace App\State\Provider\Playlist;

use ApiPlatform\Doctrine\Orm\Paginator as OrmPaginator;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Playlist\PlaylistOutput;
use App\Entity\Playlist;
use App\Repository\PlaylistRepository;
use App\Security\PlaylistOwnerExtension;
use App\State\Pagination\MappingPaginator;
use App\State\PlaylistOutputMapper;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /api/playlists`, filtered by `?concertId=`, owner-scoped (spec 14 §6).
 *
 * @implements ProviderInterface<MappingPaginator<object, PlaylistOutput>>
 */
final readonly class PlaylistCollectionProvider implements ProviderInterface
{
    private const int ITEMS_PER_PAGE = 20;

    public function __construct(
        private PlaylistRepository $repository,
        private PlaylistOwnerExtension $ownerExtension,
        private PlaylistOutputMapper $mapper,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MappingPaginator
    {
        /** @var Request $request */
        $request = $context['request'];

        $queryBuilder = $this->repository->createQueryBuilder('p');
        $this->ownerExtension->applyToCollection($queryBuilder, new QueryNameGenerator(), Playlist::class, $operation);

        $concertId = $request->query->get('concertId');
        if (is_numeric($concertId)) {
            $queryBuilder->andWhere('p.concert = :concertId')->setParameter('concertId', (int) $concertId);
        }

        $page = max(1, $request->query->getInt('page', 1));

        $queryBuilder
            ->addOrderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * self::ITEMS_PER_PAGE)
            ->setMaxResults(self::ITEMS_PER_PAGE);

        $ormPaginator = new OrmPaginator(new DoctrinePaginator($queryBuilder->getQuery(), true));

        return new MappingPaginator($ormPaginator, function (object $playlist): PlaylistOutput {
            \assert($playlist instanceof Playlist);

            return $this->mapper->map($playlist);
        });
    }
}
