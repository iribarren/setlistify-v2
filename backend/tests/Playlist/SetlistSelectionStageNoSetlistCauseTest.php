<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\NoSetlistCause;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\ResultKind;

/**
 * T-2: `SetlistSelectionStage` writes a `cause` param matching the band's resolution state at the
 * moment of the miss (AC-1.1). Each case avoids any real setlist.fm reachability requirement except
 * the `unresolved` one (F-01/F-12, same tolerance `PlaylistPipelineDegradedOutcomesTest` already
 * establishes for a band that has never been searched).
 */
final class SetlistSelectionStageNoSetlistCauseTest extends PlaylistPipelineTestCase
{
    public function testANoPresenceBandProducesTheBandUnknownCause(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('no-presence');
        $band = $this->newBand('No Presence Band', $now);
        $band->markNoPresence($now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);

        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);

        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'n');
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $this->assertCauseForSoleBand($job, NoSetlistCause::BandUnknown, $band->getName());
    }

    public function testAnAmbiguousBandProducesTheBandAmbiguousCause(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('ambiguous');
        $band = $this->newBand('Ambiguous Band', $now);
        $band->markAmbiguous($now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);

        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);

        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'm');
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $this->assertCauseForSoleBand($job, NoSetlistCause::BandAmbiguous, $band->getName());
    }

    public function testAResolvedBandWithOnlyAnEmptyCachedSetlistProducesTheNoSetlistForShowCause(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('resolved-empty');
        $band = $this->newBand('Resolved Empty Band', $now);
        $band->resolveTo('mbid-'.uniqid('', true), $band->getName(), $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);

        // A cached setlist that IS non-empty as a query result (so `cachedCandidates()` short-
        // circuits `fetchOnePage()` and no setlist.fm call is attempted) but empty of songs (so
        // `SubstantialSetlistSelector::select()` filters it out and returns null) — F-03 exactly.
        $emptySetlist = $this->newCachedSetlist($band, $now->modify('-1 month'), [], $now);

        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);

        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        self::assertSame(0, $emptySetlist->getSongCount(), 'sanity: newCachedSetlist() with no titles must produce a zero-song setlist.');

        $job = $this->newJob($user, $concert, $account, $now, 'r');
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $this->assertCauseForSoleBand($job, NoSetlistCause::NoSetlistForShow, $band->getName());
    }

    public function testAnUnresolvedBandProducesTheIdentityUnavailableCauseWhenTheJobCompletes(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->newUser('unresolved');
        // Never searched before (Band::RESOLUTION_UNRESOLVED, the constructor default) — this is
        // the one case that attempts a real setlist.fm identity search, exactly like
        // PlaylistPipelineDegradedOutcomesTest's "band with no setlist data" scenario. A budget/
        // upstream block is itself a valid, typed outcome (F-01/F-12) — tolerated the same way.
        $band = $this->newBand('Never Searched Band', $now);
        $this->entityManager()->persist($user);
        $this->entityManager()->persist($band);

        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $this->entityManager()->persist($concert);

        $account = $this->newStreamingAccount($user, $now);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'u');
        $this->jobRepository()->save($job);

        try {
            $this->pipeline()->run($job);
        } catch (GenerationBlockedException $e) {
            self::assertContains($e->reason, [BlockedReason::SetlistfmBudget, BlockedReason::UpstreamUnavailable]);

            return;
        }

        $this->assertCauseForSoleBand($job, NoSetlistCause::IdentityUnavailable, $band->getName());
    }

    private function assertCauseForSoleBand(PlaylistGenerationJob $job, NoSetlistCause $expectedCause, string $bandName): void
    {
        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(ResultKind::NoSourceMaterial, $reloadedJob->getResultKind());

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);

        $entries = array_values(array_filter(
            $playlist->getReportSummary(),
            static fn (array $entry): bool => ReportCode::NoSetlistForBand->value === $entry['code'],
        ));

        self::assertCount(1, $entries);
        self::assertSame($bandName, $entries[0]['params']['band']);
        self::assertSame($expectedCause->value, $entries[0]['params']['cause']);
    }
}
