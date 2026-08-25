<?php

declare(strict_types=1);

namespace App\State\Provider\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Playlist\PendingChoiceAutoResolvedOutput;
use App\ApiResource\Playlist\PendingChoiceCandidateOutput;
use App\ApiResource\Playlist\PendingChoiceDecisionOutput;
use App\ApiResource\Playlist\PendingChoicesOutput;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\Model\JobState;
use App\State\PlaylistGenerationJobLocator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /api/playlist-generation-jobs/{id}/pending-choices` (docs/specs/
 * 2026-08-25-playlist-normal-mode.md). A pure projection of the persisted `pendingChoices` jsonb —
 * nothing re-derived, no provider search (AC-2.4). **No raw confidence number is ever read from here
 * into the response** (D-204, AC-2.5) — the stored `label` is the only signal exposed.
 *
 * @implements ProviderInterface<PendingChoicesOutput>
 */
final readonly class PendingChoicesProvider implements ProviderInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PendingChoicesOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::VIEW);

        if (JobState::AwaitingVersionChoice !== $job->getState()) {
            throw new UnprocessableEntityHttpException('This job is not awaiting version choices.');
        }

        /**
         * @var array{
         *     songsTotal?: int,
         *     autoResolvedCount?: int,
         *     choicesRequiredCount?: int,
         *     autoResolved?: list<array{sourcePosition: int, bandName: string, sourceTitle: string, providerTrackId: ?string, label: string, reasonCode: ?string, reasonParams: ?array<string, mixed>}>,
         *     decisions?: list<array{sourcePosition: int, segmentIndex: ?int, bandName: string, sourceTitle: string, reasonCode: ?string, reasonParams: ?array<string, mixed>, candidates: list<array{providerTrackId: string, title: ?string, artistName: ?string, albumName: ?string, releaseYear: ?int, durationMs: ?int, label: string}>}>,
         * } $pending
         */
        $pending = $job->getPendingChoices() ?? [];

        $autoResolvedJson = $pending['autoResolved'] ?? [];
        $autoResolved = array_map(static fn (array $row): PendingChoiceAutoResolvedOutput => new PendingChoiceAutoResolvedOutput(
            sourcePosition: $row['sourcePosition'],
            bandName: $row['bandName'],
            sourceTitle: $row['sourceTitle'],
            providerTrackId: $row['providerTrackId'],
            label: $row['label'],
            reasonCode: $row['reasonCode'],
            reasonParams: $row['reasonParams'],
        ), $autoResolvedJson);

        $decisionsJson = $pending['decisions'] ?? [];
        $decisions = array_map(static function (array $row): PendingChoiceDecisionOutput {
            $candidates = array_map(static fn (array $candidate): PendingChoiceCandidateOutput => new PendingChoiceCandidateOutput(
                providerTrackId: $candidate['providerTrackId'],
                title: $candidate['title'],
                artistName: $candidate['artistName'],
                albumName: $candidate['albumName'],
                releaseYear: $candidate['releaseYear'],
                durationMs: $candidate['durationMs'],
                label: $candidate['label'],
            ), $row['candidates']);

            return new PendingChoiceDecisionOutput(
                sourcePosition: $row['sourcePosition'],
                segmentIndex: $row['segmentIndex'],
                bandName: $row['bandName'],
                sourceTitle: $row['sourceTitle'],
                reasonCode: $row['reasonCode'],
                reasonParams: $row['reasonParams'],
                candidates: $candidates,
            );
        }, $decisionsJson);

        return new PendingChoicesOutput(
            jobId: $job->getId() ?? 0,
            expiresAt: $job->getExpiresAt() ?? throw new \LogicException('Suspended job has no expiresAt.'),
            songsTotal: $pending['songsTotal'] ?? $job->getSongsTotal(),
            autoResolvedCount: $pending['autoResolvedCount'] ?? \count($autoResolved),
            choicesRequiredCount: $pending['choicesRequiredCount'] ?? \count($decisions),
            autoResolved: $autoResolved,
            decisions: $decisions,
        );
    }
}
