<?php

declare(strict_types=1);

namespace App\Filter;

use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `?status=upcoming|past` (AC-3.3). A single indexed comparison against `Concert::$pastAfter`
 * (D-24) — no per-row timezone computation at query time (AC-3.7). Applied manually by
 * `App\State\Provider\ConcertCollectionProvider` (this feature's resources use custom providers,
 * D-29, so API Platform's automatic Doctrine filter pipeline is not in play); the class still exists
 * on its own so the same rule is one place, not duplicated between the provider and the OpenAPI
 * description.
 */
final readonly class ConcertStatusFilter
{
    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function apply(QueryBuilder $queryBuilder, string $alias, ?string $status): void
    {
        if (null === $status) {
            return;
        }

        $now = $this->clock->now();

        match ($status) {
            'upcoming' => $queryBuilder->andWhere(\sprintf('%s.pastAfter > :now', $alias))->setParameter('now', $now),
            'past' => $queryBuilder->andWhere(\sprintf('%s.pastAfter <= :now', $alias))->setParameter('now', $now),
            default => throw new UnprocessableEntityHttpException(\sprintf('"status" must be "upcoming" or "past", got "%s".', $status)),
        };
    }
}
