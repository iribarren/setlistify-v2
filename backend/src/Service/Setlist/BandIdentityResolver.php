<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use App\Entity\Band;
use App\Service\Concert\BandResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Turns a typed band name into a stable setlist.fm identity (US-1, US-2, US-5, D-56). Once a
 * `Band` carries an MBID, this class never re-searches for it (AC-1.4) — every later setlist.fm
 * call for that band goes by MBID alone (`SetlistGateway::fetchArtistSetlistsPage()`).
 *
 * "More than one plausible candidate" (AC-2.2) is defined narrowly: more than one candidate whose
 * name normalizes (`BandResolver::normalize()`) to the same value as the query. A single exact
 * normalized match auto-resolves even when other, non-matching candidates were also returned
 * (AC-2.3). Candidates exist but *none* match exactly is treated as ambiguous too, deliberately
 * conservative (D-56/R-3): this class never guesses between look-alike names, it only ever
 * auto-picks a genuinely unambiguous exact match.
 */
final readonly class BandIdentityResolver
{
    public function __construct(
        private SetlistGateway $gateway,
        private SetlistNormalizer $normalizer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function ensureResolved(Band $band): BandResolutionOutcome
    {
        if (Band::RESOLUTION_RESOLVED === $band->getSetlistfmResolutionState()) {
            return BandResolutionOutcome::resolved($band);
        }

        // AC-5.4: re-checking an already ambiguous/no_presence band is the nightly job's business
        // (and only within its own rules), never triggered by a plain read.
        if (Band::RESOLUTION_UNRESOLVED !== $band->getSetlistfmResolutionState()) {
            return BandResolutionOutcome::fromState($band);
        }

        $fetch = $this->gateway->searchArtist($band->getName());

        if (null === $fetch->payload) {
            return BandResolutionOutcome::unavailable($band, $fetch->reason, $fetch->budgetResetAt);
        }

        $candidates = $this->normalizer->parseArtistSearchCandidates($fetch->payload);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if ([] === $candidates) {
            $band->markNoPresence($now);
            $this->entityManager->flush();

            return BandResolutionOutcome::noPresence($band);
        }

        $queryNormalized = BandResolver::normalize($band->getName());
        $exactMatches = array_values(array_filter(
            $candidates,
            static fn (ArtistSearchCandidate $candidate): bool => BandResolver::normalize($candidate->name) === $queryNormalized,
        ));

        if (1 === \count($exactMatches)) {
            $chosen = $exactMatches[0];
            $band->resolveTo($chosen->mbid, $chosen->name, $now);
            $this->entityManager->flush();

            return BandResolutionOutcome::resolved($band);
        }

        $band->markAmbiguous($now);
        $this->entityManager->flush();

        return BandResolutionOutcome::ambiguous($band, $candidates);
    }

    /**
     * AC-5.4: a `no_presence` band is re-checked only by the nightly job, and only after at least
     * 30 days. Resets to `unresolved` and delegates to {@see ensureResolved()} when due; otherwise
     * returns the band's current state unchanged.
     */
    public function recheckNoPresenceIfDue(Band $band, \DateTimeImmutable $now, int $minimumIntervalDays = 30): BandResolutionOutcome
    {
        if (Band::RESOLUTION_NO_PRESENCE !== $band->getSetlistfmResolutionState()) {
            return BandResolutionOutcome::fromState($band);
        }

        $checkedAt = $band->getSetlistfmCheckedAt();
        $due = null === $checkedAt || $checkedAt->modify(\sprintf('+%d days', $minimumIntervalDays)) <= $now;
        if (!$due) {
            return BandResolutionOutcome::fromState($band);
        }

        $band->resetResolution($now);
        $this->entityManager->flush();

        return $this->ensureResolved($band);
    }
}
