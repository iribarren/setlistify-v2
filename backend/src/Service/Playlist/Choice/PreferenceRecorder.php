<?php

declare(strict_types=1);

namespace App\Service\Playlist\Choice;

use App\Entity\User;
use App\Entity\UserTrackPreference;
use App\Repository\UserTrackPreferenceRepository;
use Psr\Clock\ClockInterface;

/**
 * The only class allowed to read or write `UserTrackPreference` (D-198,
 * docs/specs/2026-08-25-playlist-normal-mode.md). Two load-bearing rules:
 *
 * 1. {@see findApplicable()} returns a preference only when its `providerTrackId` is still among
 *    the CURRENT candidate set (AC-5.4) — a preference is a tie-break among plausible options, never
 *    a bypass of matching.
 * 2. **This class never touches `App\Entity\TrackResolution` or its store** — a per-user override
 *    stays per-user (AC-5.5). `App\Tests\Playlist\PreferenceRecorderNeverTouchesTrackResolutionTest`
 *    is the static/behavioural proof.
 */
final readonly class PreferenceRecorder
{
    public function __construct(
        private UserTrackPreferenceRepository $repository,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<string> $currentCandidateProviderTrackIds */
    public function findApplicable(
        User $owner,
        string $provider,
        int $algorithmVersion,
        string $normalizedArtist,
        string $normalizedTitle,
        array $currentCandidateProviderTrackIds,
    ): ?UserTrackPreference {
        if ('' === $normalizedArtist || '' === $normalizedTitle) {
            return null;
        }

        $preference = $this->repository->findOneByKey($owner, $provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);
        if (null === $preference) {
            return null;
        }

        if (!\in_array($preference->getProviderTrackId(), $currentCandidateProviderTrackIds, true)) {
            // AC-5.4: the remembered track is no longer offered — ignored, not a bypass.
            return null;
        }

        return $preference;
    }

    /** AC-5.3: applied and used — bump the usage counter, announced via `ReportCode::UsedYourPreviousChoice`. */
    public function markUsed(UserTrackPreference $preference): void
    {
        $preference->recordUsage();
        $this->repository->save($preference);
    }

    /** AC-5.1: a submitted version choice writes (or overwrites) the user's preference for this song. */
    public function record(
        User $owner,
        string $provider,
        int $algorithmVersion,
        string $normalizedArtist,
        string $normalizedTitle,
        string $providerTrackId,
    ): void {
        if ('' === $normalizedArtist || '' === $normalizedTitle) {
            return;
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $existing = $this->repository->findOneByKey($owner, $provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);

        if (null !== $existing) {
            $existing->choose($providerTrackId, $now);
            $this->repository->save($existing);

            return;
        }

        $this->repository->save(new UserTrackPreference($owner, $provider, $algorithmVersion, $normalizedArtist, $normalizedTitle, $providerTrackId, $now));
    }
}
