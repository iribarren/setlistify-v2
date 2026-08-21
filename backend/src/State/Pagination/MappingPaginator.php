<?php

declare(strict_types=1);

namespace App\State\Pagination;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * Wraps an `ApiPlatform\Doctrine\Orm\Paginator` (real SQL `LIMIT`/`OFFSET` + a separate `COUNT`
 * query — not an in-memory slice) and lazily maps each entity it yields through `$map`, so a
 * Doctrine ORM query stays the source of Hydra's pagination metadata (`totalItems`, `currentPage`,
 * `itemsPerPage`) while the actually-yielded items are the DTOs this feature's resources require
 * (D-29).
 *
 * @template TIn of object
 * @template TOut of object
 *
 * @implements \IteratorAggregate<mixed, TOut>
 * @implements PaginatorInterface<TOut>
 */
final class MappingPaginator implements \IteratorAggregate, PaginatorInterface
{
    /**
     * @param PaginatorInterface<TIn> $inner
     * @param \Closure(TIn): TOut     $map
     */
    public function __construct(
        private readonly PaginatorInterface $inner,
        private readonly \Closure $map,
    ) {
    }

    public function getCurrentPage(): float
    {
        return $this->inner->getCurrentPage();
    }

    public function getItemsPerPage(): float
    {
        return $this->inner->getItemsPerPage();
    }

    public function getLastPage(): float
    {
        return $this->inner->getLastPage();
    }

    public function getTotalItems(): float
    {
        return $this->inner->getTotalItems();
    }

    public function count(): int
    {
        return $this->inner->count();
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->inner as $item) {
            yield ($this->map)($item);
        }
    }
}
