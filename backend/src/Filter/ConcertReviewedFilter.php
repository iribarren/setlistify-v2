<?php

declare(strict_types=1);

namespace App\Filter;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `?reviewed=true|false` (D-241, AC-6.6). Applied against the `ConcertReview` alias
 * `App\State\Provider\ConcertCollectionProvider` already `LEFT JOIN`s in for `reviewSummary` — no
 * extra join, and index-backed by `uniq_concert_reviews_owner_concert` (the same pair the join
 * condition matches against).
 */
final readonly class ConcertReviewedFilter
{
    public function apply(QueryBuilder $queryBuilder, string $reviewAlias, ?string $reviewed): void
    {
        if (null === $reviewed) {
            return;
        }

        match ($reviewed) {
            'true' => $queryBuilder->andWhere(\sprintf('%s.id IS NOT NULL', $reviewAlias)),
            'false' => $queryBuilder->andWhere(\sprintf('%s.id IS NULL', $reviewAlias)),
            default => throw new UnprocessableEntityHttpException(\sprintf('"reviewed" must be "true" or "false", got "%s".', $reviewed)),
        };
    }
}
