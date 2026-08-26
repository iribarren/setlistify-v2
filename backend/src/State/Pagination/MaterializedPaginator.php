<?php

declare(strict_types=1);

namespace App\State\Pagination;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * Wraps an already-iterated `PaginatorInterface` so its page can be consumed a SECOND time (e.g. to
 * batch-fetch `ConcertReview` summaries for the ids on this page, D-241) without re-running
 * `ApiPlatform\Doctrine\Orm\Paginator::getIterator()` a second time — that method is not idempotent:
 * `fetchJoinCollection: true` re-issues BOTH an id sub-query and a `WHERE IN` query on every call,
 * which would silently double the query count `App\State\Provider\ConcertCollectionProvider`'s
 * no-N+1 guarantee (AC-6.5) depends on.
 *
 * Pagination metadata (`getCurrentPage()`, `getTotalItems()`, …) is read from the ORIGINAL
 * paginator — none of those methods touch `getIterator()`, so reading them first and materializing
 * the array second is exactly one execution of the underlying SQL.
 *
 * @template T of object
 *
 * @implements \IteratorAggregate<mixed, T>
 * @implements PaginatorInterface<T>
 */
final class MaterializedPaginator implements \IteratorAggregate, PaginatorInterface
{
    /**
     * @param PaginatorInterface<T> $source
     * @param list<T>               $items  already-materialized page contents
     */
    public function __construct(
        private readonly PaginatorInterface $source,
        private readonly array $items,
    ) {
    }

    public function getCurrentPage(): float
    {
        return $this->source->getCurrentPage();
    }

    public function getItemsPerPage(): float
    {
        return $this->source->getItemsPerPage();
    }

    public function getLastPage(): float
    {
        return $this->source->getLastPage();
    }

    public function getTotalItems(): float
    {
        return $this->source->getTotalItems();
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}
