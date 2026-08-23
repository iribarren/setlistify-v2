<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Service\Playlist\Model\JobState;
use App\Service\Playlist\Model\ReportCode;

/**
 * P-1/P-2, T-INT-03 (spec 14 §8, spec 13 §10, D-133): multi-band ordering is stage order —
 * `ORDER BY billingOrder DESC`, support acts first, headliner last — and the `GENERATION_MAX_BANDS`
 * (4) / `GENERATION_MAX_SONGS` (60, from `.env.local`) caps cut from the lowest-billed end, each cut
 * producing its own `BANDS_OMITTED_FOR_LENGTH` report line.
 *
 * Five bands, sized so both caps bite: `GENERATION_MAX_BANDS` drops the 5th (lowest-billed) band
 * outright, and `GENERATION_MAX_SONGS` then drops two more of the remaining four's setlists (again
 * from the lowest-billed end) until the running total fits under 60.
 */
final class PlaylistPipelineMultiBandStageOrderTest extends PlaylistPipelineTestCase
{
    public function testFourBandAndSixtySongCapsCutFromTheLowestBilledEndInStageOrder(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('multiband');
        $em->persist($user);
        $concert = $this->newConcert($user, $now);
        $em->persist($concert);

        // billingOrder: 0 = headliner. Sizes chosen so band3 (10) then band2 (15) are the ones the
        // song cap drops, leaving band1 (20, support) + band0 (30, headliner) = 50 <= 60.
        $bands = [];
        $sizes = [0 => 30, 1 => 20, 2 => 15, 3 => 10, 4 => 5];
        foreach ($sizes as $billingOrder => $songCount) {
            $band = $this->newBand(\sprintf('Band%d', $billingOrder), $now);
            $em->persist($band);
            $concert->addLineupEntry($band, $billingOrder);
            $titles = array_map(static fn (int $i): string => \sprintf('Band%d Song %d', $billingOrder, $i), range(1, $songCount));
            $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), $titles, $now);
            $bands[$billingOrder] = $band;
        }

        $account = $this->newStreamingAccount($user, $now);
        $em->persist($account);
        $em->flush();

        $job = $this->newJob($user, $concert, $account, $now, 'm');
        $this->jobRepository()->save($job);

        $this->pipeline()->run($job);

        $this->entityManager()->clear();
        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Completed, $reloadedJob->getState());
        self::assertSame(50, $reloadedJob->getSongsTotal(), 'Band1 (20) + Band0 (30) after both caps.');

        // `selectedSetlists` records every band selected before the length caps trim the playlist
        // (band0..band3 — band4 was already excluded by GENERATION_MAX_BANDS above); which bands'
        // SONGS actually made it into the final playlist is what the BANDS_OMITTED_FOR_LENGTH report
        // entries (asserted below) and the track count communicate.
        $selected = $reloadedJob->getSelectedSetlists();
        self::assertNotNull($selected);
        self::assertCount(4, $selected, 'Every band the max-bands cap left standing is selected, before the song-length cap trims the playlist itself.');

        $playlist = $this->playlistRepository()->findOneBy(['job' => $reloadedJob]);
        self::assertNotNull($playlist);

        $tracks = $playlist->getTracks()->toArray();
        self::assertCount(50, $tracks);

        // Stage order: band1 (support act, billingOrder 1) plays BEFORE band0 (headliner,
        // billingOrder 0) — the opposite of billing order, and the one place the API's ordering and
        // the playlist's ordering deliberately disagree (spec 13 §10).
        $firstTrack = $tracks[0];
        $lastTrack = $tracks[\count($tracks) - 1];
        self::assertSame('Band1 Song 1', $firstTrack->getSourceTitle());
        self::assertSame('Band0 Song 30', $lastTrack->getSourceTitle());

        foreach (\array_slice($tracks, 0, 20) as $track) {
            self::assertStringStartsWith('Band1 ', $track->getSourceTitle(), 'The first 20 tracks are band1 (support), in stage order.');
        }
        foreach (\array_slice($tracks, 20, 30) as $track) {
            self::assertStringStartsWith('Band0 ', $track->getSourceTitle(), 'The last 30 tracks are band0 (headliner), playing last.');
        }

        $reportCodes = array_map(static fn (array $entry): string => $entry['code'], $playlist->getReportSummary());
        $bandsOmittedEntries = array_values(array_filter($playlist->getReportSummary(), static fn (array $e): bool => ReportCode::BandsOmittedForLength->value === $e['code']));

        self::assertContains(ReportCode::BandsOmittedForLength->value, $reportCodes);
        self::assertCount(3, $bandsOmittedEntries, 'One entry for the max-bands cut (band4) and one each for band3 and band2, dropped individually by the song cap.');

        $omittedBandNames = [];
        foreach ($bandsOmittedEntries as $entry) {
            $names = $entry['params']['bands'];
            self::assertIsArray($names);
            foreach ($names as $name) {
                $omittedBandNames[] = $name;
            }
        }
        self::assertContains($bands[4]->getName(), $omittedBandNames, 'GENERATION_MAX_BANDS dropped band4 outright.');
        self::assertContains($bands[3]->getName(), $omittedBandNames, 'GENERATION_MAX_SONGS dropped band3 from the lowest-billed end.');
        self::assertContains($bands[2]->getName(), $omittedBandNames, 'GENERATION_MAX_SONGS then dropped band2 too.');
        self::assertNotContains($bands[1]->getName(), $omittedBandNames);
        self::assertNotContains($bands[0]->getName(), $omittedBandNames);
    }
}
