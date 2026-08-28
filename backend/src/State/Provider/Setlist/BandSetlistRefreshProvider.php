<?php

declare(strict_types=1);

namespace App\State\Provider\Setlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Setlist\BandSetlistRefreshOutput;
use App\Entity\Band;
use App\Repository\BandRepository;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\State\BandNotOnYourConcertsException;
use App\State\BandOwnershipChecker;
use App\State\Setlist\BandSetlistRefreshOutputMapper;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/bands/{id}/setlist-refresh` (docs/specs/2026-08-27-instant-setlist-refresh.md, AC-3.4).
 * Never `404` for a band that's never been refreshed (AC-3.6) — only for an unknown band id.
 *
 * @implements ProviderInterface<BandSetlistRefreshOutput>
 */
final readonly class BandSetlistRefreshProvider implements ProviderInterface
{
    public function __construct(
        private BandRepository $bandRepository,
        private BandOwnershipChecker $ownershipChecker,
        private SetlistRefreshCoordinator $coordinator,
        private BandSetlistRefreshOutputMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BandSetlistRefreshOutput
    {
        $band = $this->bandRepository->find($uriVariables['bandId'] ?? null);
        if (!$band instanceof Band) {
            throw new NotFoundHttpException();
        }

        if (!$this->ownershipChecker->ownsAConcertFeaturing($band)) {
            throw new BandNotOnYourConcertsException();
        }

        $record = $this->coordinator->getRecord($band->getId() ?? 0);

        if (isset($context['request']) && $context['request'] instanceof Request) {
            $retryAfter = BandSetlistRefreshOutputMapper::retryAfterSeconds($record, \DateTimeImmutable::createFromInterface($this->clock->now()));
            if (null !== $retryAfter) {
                $context['request']->attributes->set('_setlist_refresh_retry_after', $retryAfter);
            }
        }

        return $this->mapper->fromRecord($band, $record);
    }
}
