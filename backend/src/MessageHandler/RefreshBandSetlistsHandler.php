<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Band;
use App\Message\RefreshBandSetlistsMessage;
use App\Repository\BandRepository;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\BandResolutionOutcome;
use App\Service\Setlist\CachedFetch;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\Service\Setlist\SetlistRefreshMetrics;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Instant setlist refresh's async work (docs/specs/2026-08-27-instant-setlist-refresh.md, D-256,
 * AC-3.2). Zero outbound calls happen on the request thread that dispatched this (AC-3.1) — this
 * handler is where every outbound call actually happens.
 *
 * `$identityAlreadySettled` (the pick's completion path, AC-6.12) skips `forceResolve()` entirely —
 * the identity write already happened synchronously in `ResolveBandIdentityProcessor` — and fetches
 * only the index page (**at most 1** outbound request, vs. the plain trigger's **at most 2**,
 * AC-2.7).
 *
 * A handler failure (upstream error, unexpected exception) is recorded as `state: failed` and never
 * retried indefinitely (AC-3.7) — a retry is another budget unit, and Messenger's default retry
 * policy is not overridden for this message precisely so one redelivery is the ceiling.
 */
#[AsMessageHandler]
final readonly class RefreshBandSetlistsHandler
{
    public function __construct(
        private BandRepository $bandRepository,
        private BandIdentityResolver $resolver,
        private SetlistGateway $gateway,
        private SetlistNormalizer $normalizer,
        private SetlistRefreshCoordinator $coordinator,
        private SetlistRefreshMetrics $metrics,
        private ClockInterface $clock,
        private float $refreshNowTokenWaitSeconds,
    ) {
    }

    public function __invoke(RefreshBandSetlistsMessage $message): void
    {
        $band = $this->bandRepository->find($message->bandId);
        if (!$band instanceof Band) {
            return; // Deleted since the trigger was accepted — nothing to do.
        }

        $this->coordinator->markRunning($message->bandId);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        try {
            if ($message->identityAlreadySettled) {
                // AC-6.12/D-279: the write already happened synchronously in
                // ResolveBandIdentityProcessor — nothing to search for.
                $outcome = BandResolutionOutcome::resolved($band);
            } else {
                $outcome = $this->resolver->forceResolve($band, $now);
                $this->metrics->recordRequestSpent(); // AC-9.1: one search attempt through the gate.
            }
        } catch (\Throwable) {
            $this->coordinator->markFailed($message->bandId, 'upstream_unavailable', null, \DateTimeImmutable::createFromInterface($this->clock->now()));

            return;
        }

        if (Band::RESOLUTION_RESOLVED !== $outcome->state) {
            if (null !== $outcome->unavailableReason) {
                $this->coordinator->markFailed($message->bandId, $outcome->unavailableReason, $outcome->budgetResetAt, \DateTimeImmutable::createFromInterface($this->clock->now()));

                return;
            }

            // AC-6.1: ambiguous (or no_presence) is a WORKED refresh — succeeded, reporting the
            // outcome the search actually found. No index fetch is attempted (AC-2.7's request cap).
            $this->coordinator->markSucceeded(
                $message->bandId,
                $outcome->state,
                $outcome->candidates,
                CachedFetch::live([], \DateTimeImmutable::createFromInterface($this->clock->now())),
                \DateTimeImmutable::createFromInterface($this->clock->now()),
            );

            return;
        }

        $mbid = $band->getSetlistfmMbid();
        \assert(null !== $mbid);

        $fetch = $this->gateway->refreshArtistSetlistsPageOne($mbid, $this->refreshNowTokenWaitSeconds);
        $this->metrics->recordRequestSpent(); // AC-9.1: one index-page attempt through the gate.

        if (null === $fetch->payload) {
            \assert(null !== $fetch->reason);
            $this->coordinator->markFailed($message->bandId, $fetch->reason, $fetch->budgetResetAt, \DateTimeImmutable::createFromInterface($this->clock->now()));

            return;
        }

        // AC-2.5: written through both cache tiers by SetlistCache already — this only projects
        // into the queryable Setlist/Song rows, same as every other reader of a fetched page.
        $this->normalizer->hydrateSetlistsPage($band, $fetch->payload, $fetch->fetchedAt ?? $now);

        $this->coordinator->markSucceeded($message->bandId, Band::RESOLUTION_RESOLVED, [], $fetch, \DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
