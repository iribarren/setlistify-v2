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

        return $this->classifySearchResult($band, $fetch);
    }

    /**
     * Instant setlist refresh (docs/specs/2026-08-27-instant-setlist-refresh.md, D-263, AC-2.1).
     * Forces re-resolution even from `ambiguous`/`no_presence` by resetting first — never for a
     * `resolved` band (AC-2.2, D-56 stands: nothing re-derives an identity that already exists).
     *
     * `ensureResolved()`'s early-return guard is untouched (AC-2.3) — this is a separate entry
     * point. The classification logic (parsing candidates, deciding exact-match vs. ambiguous) is
     * shared via {@see self::classifySearchResult()} so it exists in exactly one place, as AC-2.1
     * intends; only the *fetch* differs — this path skips the search cache's freshness check
     * (`SetlistGateway::refreshArtistSearch()`) so "try again" genuinely re-asks setlist.fm, rather
     * than replaying a search from up to a day ago.
     *
     * Callable ONLY from the refresh handler/processors (AC-2.8, statically enforced).
     */
    public function forceResolve(Band $band, \DateTimeImmutable $now): BandResolutionOutcome
    {
        if (Band::RESOLUTION_RESOLVED === $band->getSetlistfmResolutionState()) {
            return BandResolutionOutcome::resolved($band);
        }

        $band->resetResolution($now);
        $this->entityManager->flush();

        $fetch = $this->gateway->refreshArtistSearch($band->getName());

        return $this->classifySearchResult($band, $fetch);
    }

    /**
     * The user-side disambiguation pick (docs/specs/2026-08-27-instant-setlist-refresh.md,
     * D-270, D-279, amendment to D-57). Writes through `Band::resolveTo()` exactly as the
     * auto-resolver and the operator's correction do — one identity write path, three callers.
     *
     * The state precondition is D-270 itself: writes only into a vacancy (`setlistfmMbid` is
     * `null`) — never overwrites an already-resolved identity, including one resolved by another
     * user milliseconds earlier (AC-6.8/AC-6.14). Validating that `$chosen` was actually among the
     * candidates shown to this user is the *caller's* job (the processor/coordinator), not this
     * method's (D-279) — this method's job is the precondition and the write.
     *
     * Makes NO outbound call (AC-2.9) — the candidate's mbid/name were already fetched by a prior
     * search. Callable ONLY from the refresh handler/processors (AC-2.8, statically enforced).
     *
     * @throws BandAlreadyResolvedException when `$band` is no longer a vacancy
     */
    public function resolveAmbiguousChoice(Band $band, ArtistSearchCandidate $chosen, \DateTimeImmutable $now): BandResolutionOutcome
    {
        if (null !== $band->getSetlistfmMbid()) {
            throw new BandAlreadyResolvedException($band);
        }

        $band->resolveTo($chosen->mbid, $chosen->name, $now);
        $this->entityManager->flush();

        return BandResolutionOutcome::resolved($band);
    }

    private function classifySearchResult(Band $band, CachedFetch $fetch): BandResolutionOutcome
    {
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
