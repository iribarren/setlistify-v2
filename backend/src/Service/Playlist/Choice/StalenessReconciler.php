<?php

declare(strict_types=1);

namespace App\Service\Playlist\Choice;

use App\Entity\PlaylistTrack;
use App\Entity\Setlist;
use App\Repository\TrackResolutionRepository;
use App\Service\Playlist\Model\ReportCode;

/**
 * Spec 13 §6's staleness-on-resume table, one method per row (docs/specs/
 * 2026-08-25-playlist-normal-mode.md, AC-8.1). **No case here may produce `failed`, and none may
 * produce an HTTP error on the suspension endpoints (AC-8.2)** — every method below returns a report
 * code and a decided, non-error outcome, never throws for a stale-but-recoverable condition.
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
    ) {
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
