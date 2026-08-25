<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\Band;
use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\TrackResolutionRepository;
use App\Repository\UserTrackPreferenceRepository;
use App\Service\Concert\BandResolver;
use App\Service\Matching\SongNormalizer;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Streaming\Model\TrackCandidate;

/**
 * US-5 (docs/specs/2026-08-25-playlist-normal-mode.md, D-198): a preference is applied before the
 * CHOICE band is assembled, announced (never silent), ignored when its track is no longer among the
 * current candidates, and **never written to or mutates `TrackResolution`** (AC-5.5).
 */
final class NormalModePreferenceTest extends NormalModePipelineTestCase
{
    private function normalizedArtist(Band $band): string
    {
        return BandResolver::normalize($band->getName());
    }

    private function normalizedCreepTitle(): string
    {
        return self::getContainer()->get(SongNormalizer::class)->normalize('Creep')->comparisonCore;
    }

    /**
     * A second call for the same `$band` must reuse `$account` and NOT create a second cached
     * `Setlist` row — a second row would itself trigger the setlist-choice suspension (>= 2
     * candidates), which this test isn't exercising.
     */
    private function buildJobAwaitingVersionChoiceForCreep(User $user, Band $band, \DateTimeImmutable $now, string $creepCandidateTrackId, string $seed, ?StreamingAccount $account = null): PlaylistGenerationJob
    {
        $em = $this->entityManager();
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);

        $isFirstCallForThisBand = null === $account;
        if ($isFirstCallForThisBand) {
            $this->newCachedSetlist($band, new \DateTimeImmutable('2023-01-01'), ['Creep'], $now);
            $account = $this->newStreamingAccount($user, $now);
            $em->persist($account);
        }
        $em->flush();

        $this->testDoubleProvider()->scriptCandidates('Creep', [
            new TrackCandidate(
                providerTrackId: $creepCandidateTrackId,
                title: 'Creeping',
                artist: $band->getName(),
                album: null,
                durationMs: 200_000,
                isLive: false,
                isCover: false,
                confidence: 0.9,
            ),
        ]);

        $job = $this->newNormalJob($user, $concert, $account, $now, $seed);
        $this->jobRepository()->save($job);
        $this->pipeline()->run($job);

        return $job;
    }

    public function testAppliedPreferenceIsAnnouncedAndSkipsTheDecisionOnASecondGeneration(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('pref-applied');
        $em->persist($user);
        $band = $this->newBand('PrefBand', $now);
        $em->persist($band);
        $em->flush();

        // First generation: the CHOICE band suspends, the user accepts the candidate.
        $job1 = $this->buildJobAwaitingVersionChoiceForCreep($user, $band, $now, 'pref-track-1', 'p');
        self::assertSame(JobState::AwaitingVersionChoice, $job1->getState());
        $account = $job1->getStreamingAccount();

        $this->versionChoiceApplier()->apply($job1, [
            ['sourcePosition' => 0, 'segmentIndex' => null, 'providerTrackId' => 'pref-track-1'],
        ]);
        $this->pipeline()->run($job1);

        $preferenceRepository = self::getContainer()->get(UserTrackPreferenceRepository::class);
        $preference = $preferenceRepository->findOneByKey(
            $user,
            $job1->getProviderKey(),
            $job1->getAlgorithmVersion(),
            $this->normalizedArtist($band),
            $this->normalizedCreepTitle(),
        );
        self::assertNotNull($preference);
        self::assertSame('pref-track-1', $preference->getProviderTrackId());
        self::assertSame(0, $preference->getUsedCount());

        // Second generation for the same user/band, same candidate available: AC-5.2 — resolved
        // before the CHOICE band is assembled, never a decision.
        $job2 = $this->buildJobAwaitingVersionChoiceForCreep($user, $band, $now, 'pref-track-1', 'q', $account);

        $this->entityManager()->clear();
        $job2 = $this->jobRepository()->find($job2->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $job2);
        self::assertSame(JobState::Completed, $job2->getState(), 'AC-5.2: an empty CHOICE band (song auto-resolved by preference) never suspends.');

        $playlist = $this->playlistRepository()->findOneBy(['job' => $job2]);
        self::assertNotNull($playlist);
        $track = $playlist->getTracks()->first();
        self::assertInstanceOf(\App\Entity\PlaylistTrack::class, $track);
        self::assertSame(TrackOutcome::Matched, $track->getOutcome());
        self::assertSame('pref-track-1', $track->getProviderTrackId());
        // AC-5.3: never silent.
        self::assertSame(ReportCode::UsedYourPreviousChoice, $track->getReasonCode());

        $preferenceAfter = $preferenceRepository->findOneByKey(
            $user,
            $job2->getProviderKey(),
            $job2->getAlgorithmVersion(),
            $this->normalizedArtist($band),
            $this->normalizedCreepTitle(),
        );
        self::assertNotNull($preferenceAfter);
        self::assertSame(1, $preferenceAfter->getUsedCount(), 'MatchingStage applied the preference once.');
    }

    public function testStalePreferenceIsIgnoredAndTheSongBecomesADecisionAgain(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('pref-stale');
        $em->persist($user);
        $band = $this->newBand('StaleBand', $now);
        $em->persist($band);
        $em->flush();

        $job1 = $this->buildJobAwaitingVersionChoiceForCreep($user, $band, $now, 'stale-track-1', 's');
        $account = $job1->getStreamingAccount();
        $this->versionChoiceApplier()->apply($job1, [
            ['sourcePosition' => 0, 'segmentIndex' => null, 'providerTrackId' => 'stale-track-1'],
        ]);
        $this->pipeline()->run($job1);

        // Tier 1 (spec 12 §8): without this, job2's matching would hit the global resolution cache
        // (DB AND its Redis front tier) and never re-search at all, which would prove nothing about
        // AC-5.4. Evicting it forces a fresh search — the same real-world trigger as a
        // `TrackResolution` invalidation (F-13, an `algorithmVersion` bump) — so the CURRENT
        // candidate set genuinely no longer contains the track the stored preference points to.
        // `TrackResolutionStore::delete()`, not the repository directly: the repository alone would
        // leave the Redis-cached hit in place for its 300s TTL.
        self::getContainer()->get(\App\Service\Matching\Cache\TrackResolutionStore::class)->delete(
            $job1->getProviderKey(),
            $job1->getAlgorithmVersion(),
            $this->normalizedArtist($band),
            $this->normalizedCreepTitle(),
        );

        // Second generation: the previously-chosen track is no longer among the candidates offered
        // (a different candidate id this time) — AC-5.4.
        $job2 = $this->buildJobAwaitingVersionChoiceForCreep($user, $band, $now, 'different-track-2', 't', $account);

        self::assertSame(JobState::AwaitingVersionChoice, $job2->getState(), 'AC-5.4: a stale preference never bypasses matching — the song is a decision again.');
        $pending = $job2->getPendingChoices();
        self::assertNotNull($pending);
        self::assertSame(1, $pending['choicesRequiredCount']);
    }

    public function testAcceptingAVersionChoiceNeverWritesToOrMutatesTrackResolution(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('pref-no-tr');
        $em->persist($user);
        $band = $this->newBand('SafeBand', $now);
        $em->persist($band);
        $em->flush();

        $job = $this->buildJobAwaitingVersionChoiceForCreep($user, $band, $now, 'safe-track-1', 'z');

        $trackResolutionRepository = self::getContainer()->get(TrackResolutionRepository::class);
        $normalizedArtist = $this->normalizedArtist($band);
        $normalizedTitle = $this->normalizedCreepTitle();

        $before = $trackResolutionRepository->findOneByKey($job->getProviderKey(), $job->getAlgorithmVersion(), $normalizedArtist, $normalizedTitle);
        self::assertNotNull($before, 'TrackMatcher itself already wrote the global cache row during matching.');
        $beforeSnapshot = [$before->getProviderTrackId(), round($before->getConfidence(), 6), $before->getOutcome()];
        $countBefore = \count($trackResolutionRepository->findAll());

        $this->versionChoiceApplier()->apply($job, [
            ['sourcePosition' => 0, 'segmentIndex' => null, 'providerTrackId' => 'safe-track-1'],
        ]);

        $this->entityManager()->clear();
        $trackResolutionRepository = self::getContainer()->get(TrackResolutionRepository::class);
        $after = $trackResolutionRepository->findOneByKey($job->getProviderKey(), $job->getAlgorithmVersion(), $normalizedArtist, $normalizedTitle);
        self::assertNotNull($after);
        self::assertSame($beforeSnapshot, [$after->getProviderTrackId(), round($after->getConfidence(), 6), $after->getOutcome()], 'AC-5.5: the global resolution must be untouched by a version-choice submission.');
        self::assertCount($countBefore, $trackResolutionRepository->findAll(), 'AC-5.5: no new TrackResolution row was written by the preference.');
    }
}
