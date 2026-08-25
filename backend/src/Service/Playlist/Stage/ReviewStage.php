<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Playlist;
use App\Entity\PlaylistGenerationJob;
use App\Entity\PlaylistTrack;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\JobMode;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use Psr\Clock\ClockInterface;

/**
 * Fast mode: a no-op — the CHOICE band (`matched_low_confidence`) is included and flagged, never
 * dropped (spec 12, resolved Q1); `MatchingStage` already wrote that outcome.
 *
 * **Normal mode's version-choice guard lives here (T-07, D-188, docs/specs/
 * 2026-08-25-playlist-normal-mode.md)** — the only other guard is `SetlistSelectionStage`'s (T-04);
 * nowhere else in `App\Service\Playlist\` may branch on `$job->getMode()` (AC-7.2). When the CHOICE
 * band is non-empty, this stage persists `pendingChoices`, suspends via
 * `JobStateMachine::suspendForVersionChoice()` and returns `true` so `PlaylistPipeline` stops (a
 * boolean check, not a mode check). **An empty CHOICE band skips the suspension entirely** (D-195) —
 * exactly what keeps `PlaylistPipelineHappyPathTest`'s shared-pipeline property true for a
 * Normal-mode job with nothing ambiguous (AC-7.1).
 */
final readonly class ReviewStage
{
    public function __construct(
        private JobStateMachine $stateMachine,
        private ClockInterface $clock,
        private int $suspendedVersionChoiceTtlSeconds,
    ) {
    }

    /**
     * @param array<int, list<array<string, mixed>>> $choiceDigestsByOrdinal `MatchingStage::run()`'s
     *                                                                       return value — the only
     *                                                                       source for `pendingChoices`
     *                                                                       candidates (D-200: no
     *                                                                       second search here)
     *
     * @return bool true when this call suspended the job — the caller must stop
     */
    public function run(PlaylistGenerationJob $job, Playlist $playlist, array $choiceDigestsByOrdinal): bool
    {
        if (JobMode::Normal !== $job->getMode()) {
            return false; // Fast mode never suspends here — see class docblock.
        }

        /** @var list<PlaylistTrack> $choiceRows */
        $choiceRows = [];
        /** @var list<PlaylistTrack> $autoRows */
        $autoRows = [];
        foreach ($playlist->getTracks() as $track) {
            if (TrackOutcome::MatchedLowConfidence === $track->getOutcome()) {
                $choiceRows[] = $track;
            } elseif (TrackOutcome::Matched === $track->getOutcome()) {
                $autoRows[] = $track;
            }
        }

        if ([] === $choiceRows) {
            // D-195/AC-2.7: nothing ambiguous — straight through to `building`, no suspension, no
            // confirm screen either (the client renders none because the state never suspends).
            return false;
        }

        $autoResolvedJson = array_map(static fn (PlaylistTrack $track): array => [
            'sourcePosition' => $track->getSourcePosition(),
            'bandName' => $track->getSourceBand()->getName(),
            'sourceTitle' => $track->getSourceTitle(),
            'providerTrackId' => $track->getProviderTrackId(),
            'label' => ReportCode::UsedYourPreviousChoice === $track->getReasonCode() ? 'your_previous_choice' : 'top_pick',
            'reasonCode' => $track->getReasonCode()?->value,
            'reasonParams' => $track->getReasonParams(),
        ], $autoRows);

        $decisionsJson = [];
        foreach ($choiceRows as $track) {
            $digest = $choiceDigestsByOrdinal[$track->getOrdinal()] ?? [];
            $candidateCount = \count($digest);

            $candidatesJson = [];
            foreach ($digest as $index => $candidate) {
                $candidatesJson[] = [
                    'providerTrackId' => $candidate['providerTrackId'] ?? null,
                    'title' => $candidate['title'] ?? null,
                    'artistName' => $candidate['artist'] ?? null,
                    'albumName' => $candidate['album'] ?? null,
                    'releaseYear' => null,
                    'durationMs' => $candidate['durationMs'] ?? null,
                    'label' => self::candidateLabel($index, $candidateCount),
                ];
            }

            $decisionsJson[] = [
                'sourcePosition' => $track->getSourcePosition(),
                'segmentIndex' => $track->getSegmentIndex(),
                'bandName' => $track->getSourceBand()->getName(),
                'sourceTitle' => $track->getSourceTitle(),
                'reasonCode' => $track->getReasonCode()?->value,
                'reasonParams' => $track->getReasonParams(),
                'candidates' => $candidatesJson,
            ];
        }

        $choicesRequiredCount = \count($choiceRows);

        $job->setPendingChoices([
            'songsTotal' => $job->getSongsTotal(),
            'autoResolvedCount' => \count($autoRows),
            'choicesRequiredCount' => $choicesRequiredCount,
            'autoResolved' => $autoResolvedJson,
            'decisions' => $decisionsJson,
        ]);

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $job->setChoiceCounts($choicesRequiredCount, 0, $now);

        $expiresAt = $now->modify(\sprintf('+%d seconds', $this->suspendedVersionChoiceTtlSeconds));
        $this->stateMachine->suspendForVersionChoice($job, $expiresAt);

        return true;
    }

    private static function candidateLabel(int $index, int $candidateCount): string
    {
        if (1 === $candidateCount) {
            return 'only_match';
        }

        return 0 === $index ? 'top_pick' : 'alternative';
    }
}
