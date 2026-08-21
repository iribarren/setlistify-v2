<?php

declare(strict_types=1);

namespace App\Filter;

use App\Service\Concert\BandResolver;
use Doctrine\ORM\QueryBuilder;

/**
 * `?band=<query>` (US-4). Uses the exact same normalization as dedup
 * (`BandResolver::normalize()`, AC-4.2) so search and dedup can never drift apart. A join + `DISTINCT`
 * (not a naive join) so a lineup with several matching bands doesn't duplicate the concert row
 * (AC-4.3, R-6). An empty or whitespace-only query is treated as absent (AC-4.4).
 */
final readonly class ConcertBandNameFilter
{
    public function apply(QueryBuilder $queryBuilder, string $concertAlias, ?string $query): void
    {
        $trimmed = null !== $query ? trim($query) : '';
        if ('' === $trimmed) {
            return;
        }

        $normalized = BandResolver::normalize($trimmed);
        if ('' === $normalized) {
            return;
        }

        $queryBuilder
            ->distinct()
            ->join(\sprintf('%s.concertBands', $concertAlias), 'band_name_filter_cb')
            ->join('band_name_filter_cb.band', 'band_name_filter_band')
            ->andWhere('band_name_filter_band.normalizedName LIKE :bandNameQuery')
            ->setParameter('bandNameQuery', '%'.addcslashes($normalized, '%_\\').'%');
    }
}
