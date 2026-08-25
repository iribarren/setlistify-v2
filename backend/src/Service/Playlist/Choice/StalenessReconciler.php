<?php

declare(strict_types=1);

namespace App\Service\Playlist\Choice;

use App\Entity\Band;
use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Entity\Setlist;
use App\Repository\SetlistRepository;
use App\Repository\TrackResolutionRepository;
use App\Service\Matching\MatchProfileRegistry;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Playlist\PlaylistSkeletonBuilder;
use App\Service\Playlist\SubstantialSetlistSelector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Spec 13 §6's staleness-on-resume table, one method per row (docs/specs/
 * 2026-08-25-playlist-normal-mode.md, AC-8.1). **No case here may produce `failed`, and none may
 * produce an HTTP error on the suspension endpoints (AC-8.2)** — every method below returns a report
 * code and a decided, non-error outcome, never throws for a stale-but-recoverable condition.
 * `reconcileResume()` is the one method actually called from the pipeline (`MatchingStage::run()`,
 * every attempt); everything else below it is either a pure per-row decision that `reconcileResume()`
 * calls, or (`reconcileVanishedCandidate()`) a row wired in elsewhere (`InsertionStage`, F-13).
 *
 * Two rows of the table need no code here at all, because they are already handled generically,
 * outside Normal mode's own files:
 * - **Provider disabled / token expired** (T-19) — `PlaylistPipeline` re-runs `PreflightStage` and
 *   re-checks `ProviderRegistry`/`StreamingTokenManager` on every resume already; a job suspended at
 *   `awaiting_setlist_choice`/`awaiting_version_choice` re-enters the pipeline from the top the
 *   moment a choice is submitted, so a disabled provider or an expired token is caught there and
 *   raises `GenerationBlockedException` exactly as it does mid-run — `blocked`, never `failed`.
 * - **Concert deleted** (T-18) — the `concert_id` foreign key on `PlaylistGenerationJob` already
 *   cascades (`onDelete: 'CASCADE'`), from prompt 14.
 */
final readonly class StalenessReconciler
{
    public function __construct(
        private TrackResolutionRepository $trackResolutionRepository,
        private SetlistRepository $setlistRepository,
        private SubstantialSetlistSelector $substantialSelector,
        private PlaylistSkeletonBuilder $skeletonBuilder,
        private MatchProfileRegistry $profileRegistry,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * `MatchingStage::run()`'s single entry point into this class (AC-8.1) — called at the START of
     * every attempt, resumed or not, BEFORE any song is (re-)matched. Idempotent on a fresh
     * first pass: the fingerprint was just computed from the same `Setlist` row, the algorithm
     * version was just read from the same registry, and the row was just inserted, so every check
     * below is a no-op until real time has actually passed. Covers rows 1, 2 and 6 of spec 13 §6's
     * table (docs/specs/2026-08-25-playlist-normal-mode.md, AC-8.3: the fingerprint recompute
     * genuinely happens here, at resume time, never at submission time in `SetlistChoiceApplier`).
     * Rows 3 (vanished candidate, F-13) and 5 (concert deleted, FK cascade) are handled elsewhere;
     * row 4 (provider disabled/token expired) by `PlaylistPipeline`'s own pre-flight re-check.
     */
    public function reconcileResume(PlaylistGenerationJob $job, Playlist $playlist, \DateTimeImmutable $now): void
    {
        $selected = $job->getSelectedSetlists();
        $changed = false;

        if (null !== $selected) {
            foreach ($selected as $entry) {
                $bandTracks = array_values(array_filter(
                    $playlist->getTracks()->toArray(),
                    static fn (PlaylistTrack $t): bool => $t->getSourceSetlistfmId() === $entry['setlistfmId'],
                ));

                if ([] === $bandTracks) {
                    continue; // Nothing of this band's survived the song-length cap — nothing to reconcile.
                }

                $setlist = $this->setlistRepository->findOneBySetlistfmId($entry['setlistfmId']);

                $changed = (null === $setlist
                    ? $this->applyPurgedSetlistFallback($job, $playlist, $bandTracks, $now)
                    : $this->applyCorrectedSetlist($playlist, $setlist, $entry, $bandTracks, $now)
                ) || $changed;
            }
        }

        $changed = $this->applyAlgorithmVersionBump($job, $playlist, $now) || $changed;

        if ($changed) {
            $this->entityManager->flush();
        }
    }

    /**
     * @param array{bandId: int, setlistfmId: string, selectionReason: string, fingerprint: string, songCount: int} $entry
     * @param list<PlaylistTrack>                                                                                   $bandTracks
     */
    private function applyCorrectedSetlist(Playlist $playlist, Setlist $setlist, array $entry, array $bandTracks, \DateTimeImmutable $now): bool
    {
        $result = $this->reconcileCorrectedSetlist($setlist, $entry['fingerprint']);
        if (null === $result) {
            return false;
        }

        $titlesBySongId = [];
        foreach ($setlist->getSongs() as $song) {
            $titlesBySongId[$song->getId()] = $song->getTitle();
        }

        $anyReset = false;
        foreach ($bandTracks as $track) {
            $song = $track->getSourceSong();
            if (null === $song) {
                continue; // Row 3's (vanished candidate) territory, not row 1's — leave it be.
            }

            $currentTitle = $titlesBySongId[$song->getId()] ?? $song->getTitle();
            if ($currentTitle === $track->getSourceTitle()) {
                continue; // Unchanged — whatever it already resolved to (or hasn't) stands.
            }

            $track->resetForStalenessReconciliation($currentTitle);
            $anyReset = true;
        }

        if (!$anyReset) {
            return false;
        }

        [$code, $params] = $result;
        $playlist->addReportEntry($code->value, $params, $now);

        return true;
    }

    /** @param list<PlaylistTrack> $oldTracks this band's existing rows — orphaned, `sourceSong` already null */
    private function applyPurgedSetlistFallback(PlaylistGenerationJob $job, Playlist $playlist, array $oldTracks, \DateTimeImmutable $now): bool
    {
        /** @var Band $band all rows for one band share the same `sourceBand`, set once by `PlaylistSkeletonBuilder` */
        $band = $oldTracks[0]->getSourceBand();
        [$code, $params] = $this->reconcileSelectedSetlistUnavailable($band->getName());

        /** @var list<Setlist> $candidates */
        $candidates = $this->setlistRepository->createBandSetlistsQueryBuilder($band)->getQuery()->getResult();
        $selection = $this->substantialSelector->select($candidates, $now);

        $removedCount = \count($oldTracks);
        foreach ($oldTracks as $track) {
            $playlist->removeTrack($track);
        }

        // The removed rows must actually be DELETEd before any replacement is INSERTed reusing the
        // same ordinal — Doctrine orders a single flush's inserts before its deletes, which would
        // otherwise collide on `uniq_playlist_track_ordinal` even though the in-memory collection
        // already looks empty.
        $this->entityManager->flush();

        $addedCount = null !== $selection ? $this->skeletonBuilder->appendBandTracks($playlist, $band, $selection->setlist) : 0;

        $job->setSongsTotal($job->getSongsTotal() - $removedCount + $addedCount, $now);
        $playlist->addReportEntry($code->value, $params, $now);

        return true;
    }

    private function applyAlgorithmVersionBump(PlaylistGenerationJob $job, Playlist $playlist, \DateTimeImmutable $now): bool
    {
        $current = $this->profileRegistry->algorithmVersion();
        $stored = $job->getAlgorithmVersion();

        if ($stored === $current) {
            return false;
        }

        /** @var list<PlaylistTrack> $pending */
        $pending = array_values(array_filter(
            $playlist->getTracks()->toArray(),
            static fn (PlaylistTrack $t): bool => TrackOutcome::Pending === $t->getOutcome() && null !== $t->getSourceSong(),
        ));

        $result = $this->reconcileAlgorithmVersionBump($stored, $current, $pending);
        $job->setAlgorithmVersion($current);

        if (null !== $result) {
            [$code, $params] = $result;
            $playlist->addReportEntry($code->value, $params, $now);
        }

        return true;
    }

    /**
     * The setlist was corrected on setlist.fm since selection — detected by recomputing
     * `fingerprint()` at resume and comparing to the value stored on `selectedSetlists` at
     * selection time (AC-8.3: recomputed at resume, not at submission). Returns the report entry to
     * append when it changed, or `null` when it did not.
     *
     * @return array{0: ReportCode, 1: array<string, mixed>}|null
     */
    public function reconcileCorrectedSetlist(Setlist $setlist, string $storedFingerprint): ?array
    {
        $current = self::fingerprint($setlist);
        if ($current === $storedFingerprint) {
            return null;
        }

        return [ReportCode::SetlistCorrectedSinceSelection, ['band' => $setlist->getBand()->getName()]];
    }

    /**
     * `sha256` of the ordered song titles (spec 13 §6) — the real content fingerprint, distinct from
     * `selectedSetlists[].fingerprint`'s existing `setlistfmId` value (spec 14's identity marker for
     * "which show"); this is "has its song list changed since we looked".
     */
    public static function fingerprint(Setlist $setlist): string
    {
        $titles = [];
        foreach ($setlist->getSongs() as $song) {
            $titles[] = $song->getTitle();
        }

        return hash('sha256', implode('|', $titles));
    }

    /**
     * `algorithmVersion` was bumped while the job slept. A human decision outranks a formula: every
     * row already resolved by an explicit user choice (Matched via a submitted version choice, or a
     * decline) is kept untouched; only rows still `pending`/`matched_low_confidence` from the OLD
     * version are eligible for re-scoring by `MatchingStage` on this resume.
     *
     * @param list<PlaylistTrack> $tracks
     *
     * @return array{0: ReportCode, 1: array<string, mixed>}|null
     */
    public function reconcileAlgorithmVersionBump(int $storedAlgorithmVersion, int $currentAlgorithmVersion, array $tracks): ?array
    {
        if ($storedAlgorithmVersion === $currentAlgorithmVersion) {
            return null;
        }

        return [ReportCode::RescoredAfterAlgorithmUpdate, ['songsAffected' => \count($tracks)]];
    }

    /**
     * F-13: the chosen candidate no longer exists at insert time. Per-track `not_found`, and the
     * durable `TrackResolution` row (if any) is deleted so the next generation re-resolves it — never
     * left pointing at a vanished track. Returns the report entry to append.
     *
     * @return array{0: ReportCode, 1: array<string, mixed>}
     */
    public function reconcileVanishedCandidate(string $provider, int $algorithmVersion, string $normalizedArtist, string $normalizedTitle): array
    {
        $existing = $this->trackResolutionRepository->findOneByKey($provider, $algorithmVersion, $normalizedArtist, $normalizedTitle);
        if (null !== $existing) {
            $this->trackResolutionRepository->delete($existing);
        }

        return [ReportCode::TrackVanished, []];
    }

    /**
     * The chosen setlist itself was purged from the cache between selection and resume. Falls back
     * to D-132's automatic "most recent substantial" rule over whatever is currently cached — never
     * a hard failure over a row that simply expired from cache.
     *
     * @return array{0: ReportCode, 1: array<string, mixed>}
     */
    public function reconcileSelectedSetlistUnavailable(string $bandName): array
    {
        return [ReportCode::SelectedSetlistUnavailable, ['band' => $bandName]];
    }
}
