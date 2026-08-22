<?php

declare(strict_types=1);

namespace App\State\Provider\Setlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Setlist\BandSearchCandidateOutput;
use App\ApiResource\Setlist\BandSetlistsOutput;
use App\ApiResource\Setlist\FreshnessEnvelope;
use App\ApiResource\Setlist\SetlistSummaryOutput;
use App\Entity\Band;
use App\Entity\Setlist;
use App\Repository\BandRepository;
use App\Repository\SetlistRepository;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\CachedFetch;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/bands/{bandId}/setlists` (US-3). Resolves the band's setlist.fm identity on first read
 * (US-1, US-2, US-5) if it hasn't been already (AC-1.4 — never re-searches once resolved), then
 * lazily backfills enough of the band's cached setlist index to cover the requested page before
 * paginating over it in SQL (AC-3.5) — never proxying one API page to one setlist.fm page.
 *
 * @implements ProviderInterface<BandSetlistsOutput>
 */
final readonly class BandSetlistsProvider implements ProviderInterface
{
    /** setlist.fm's own default page size for `/artist/{mbid}/setlists` (assumption, spec Dependencies). */
    private const int UPSTREAM_PAGE_SIZE = 20;

    /** Bounds how many upstream pages one read will lazily backfill (R-1: budget is the scarce resource). */
    private const int MAX_UPSTREAM_PAGES = 10;

    private const int DEFAULT_ITEMS_PER_PAGE = 20;
    private const int MAX_ITEMS_PER_PAGE = 100;

    public function __construct(
        private BandRepository $bandRepository,
        private BandIdentityResolver $resolver,
        private SetlistGateway $gateway,
        private SetlistNormalizer $normalizer,
        private SetlistRepository $setlistRepository,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BandSetlistsOutput
    {
        $band = $this->bandRepository->find($uriVariables['bandId'] ?? null);
        if (!$band instanceof Band) {
            throw new NotFoundHttpException('Band not found.');
        }

        /** @var Request $request */
        $request = $context['request'];
        [$page, $itemsPerPage] = $this->pagination($request);

        $outcome = $this->resolver->ensureResolved($band);

        if (Band::RESOLUTION_RESOLVED !== $outcome->state) {
            $freshness = null !== $outcome->unavailableReason
                ? FreshnessEnvelope::degraded('cache', null, $outcome->unavailableReason, $outcome->budgetResetAt)
                : FreshnessEnvelope::fresh('live', null);

            $candidates = array_map(
                static fn ($c): BandSearchCandidateOutput => new BandSearchCandidateOutput($c->mbid, $c->name, $c->sortName, $c->disambiguation),
                $outcome->candidates,
            );

            return new BandSetlistsOutput($outcome->state, $candidates, [], 0, $page, $itemsPerPage, $freshness);
        }

        $mbid = $band->getSetlistfmMbid();
        \assert(null !== $mbid);

        $lastFetch = $this->backfillUntilCovered($band, $mbid, $page * $itemsPerPage);

        $total = $this->setlistRepository->countForBand($band);
        /** @var list<Setlist> $rows */
        $rows = $this->setlistRepository->createBandSetlistsQueryBuilder($band)
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();

        $items = array_map(
            static fn (Setlist $s): SetlistSummaryOutput => new SetlistSummaryOutput(
                $s->getSetlistfmId(),
                $s->getEventDate()->format('Y-m-d'),
                $s->getVenueName(),
                $s->getVenueCity(),
                $s->getVenueCountry(),
                $s->getTourName(),
                $s->getSongCount(),
            ),
            $rows,
        );

        $freshness = null !== $lastFetch
            ? FreshnessEnvelopeMapper::from($lastFetch)
            : FreshnessEnvelope::fresh('cache', \DateTimeImmutable::createFromInterface($this->clock->now()));

        return new BandSetlistsOutput('resolved', [], $items, $total, $page, $itemsPerPage, $freshness);
    }

    /**
     * Fetches successive upstream pages only until enough entries are cached to cover
     * `$minimumCount`, the band's known index is exhausted, or the bound is hit — never further
     * (AC-10.6: no read path speculatively checks "anything new?" beyond what it needs right now).
     */
    private function backfillUntilCovered(Band $band, string $mbid, int $minimumCount): ?CachedFetch
    {
        $cached = $this->setlistRepository->countForBand($band);
        $upstreamPage = (int) floor($cached / self::UPSTREAM_PAGE_SIZE) + 1;
        $lastFetch = null;

        while ($cached < $minimumCount && $upstreamPage <= self::MAX_UPSTREAM_PAGES) {
            $fetch = $this->gateway->fetchArtistSetlistsPage($mbid, $upstreamPage);
            $lastFetch = $fetch;

            if (null === $fetch->payload) {
                break; // degraded — serve whatever is already cached (D-63)
            }

            $hydrated = $this->normalizer->hydrateSetlistsPage($band, $fetch->payload, $fetch->fetchedAt ?? \DateTimeImmutable::createFromInterface($this->clock->now()));
            $entriesOnThisPage = \count($hydrated['setlists']);
            $cached = $this->setlistRepository->countForBand($band);

            if ($entriesOnThisPage < self::UPSTREAM_PAGE_SIZE) {
                break; // that was setlist.fm's last page for this band
            }

            ++$upstreamPage;
        }

        return $lastFetch;
    }

    /** @return array{int, int} */
    private function pagination(Request $request): array
    {
        $page = max(1, $request->query->getInt('page', 1));
        $itemsPerPage = max(1, min($request->query->getInt('itemsPerPage', self::DEFAULT_ITEMS_PER_PAGE), self::MAX_ITEMS_PER_PAGE));

        return [$page, $itemsPerPage];
    }
}
