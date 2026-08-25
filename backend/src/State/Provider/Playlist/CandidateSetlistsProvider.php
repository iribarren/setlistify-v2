<?php

declare(strict_types=1);

namespace App\State\Provider\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Playlist\CandidateSetlistBandOutput;
use App\ApiResource\Playlist\CandidateSetlistOutput;
use App\ApiResource\Playlist\CandidateSetlistsOutput;
use App\Security\Voter\PlaylistGenerationJobVoter;
use App\Service\Playlist\Model\JobState;
use App\State\PlaylistGenerationJobLocator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /api/playlist-generation-jobs/{id}/candidate-setlists` (docs/specs/
 * 2026-08-25-playlist-normal-mode.md). A pure projection of the persisted `candidateSetlists` jsonb
 * — nothing re-derived, no setlist.fm call (AC-1.2).
 *
 * @implements ProviderInterface<CandidateSetlistsOutput>
 */
final readonly class CandidateSetlistsProvider implements ProviderInterface
{
    public function __construct(
        private PlaylistGenerationJobLocator $locator,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CandidateSetlistsOutput
    {
        $job = $this->locator->locate($uriVariables['id'] ?? null, PlaylistGenerationJobVoter::VIEW);

        if (JobState::AwaitingSetlistChoice !== $job->getState()) {
            throw new UnprocessableEntityHttpException('This job is not awaiting a setlist choice.');
        }

        /** @var list<array{bandId: int, bandName: string, billingOrder: int, recommendedSetlistfmId: ?string, recommendedReason: ?string, noSetlistCause: ?string, candidates: list<array{setlistfmId: string, eventDate: string, venueName: ?string, cityName: ?string, countryCode: ?string, tourName: ?string, songCount: int, isSameNight: bool, url: ?string}>}> $bandsJson */
        $bandsJson = $job->getCandidateSetlists() ?? [];

        $bands = array_map(static function (array $band): CandidateSetlistBandOutput {
            $candidates = array_map(static fn (array $candidate): CandidateSetlistOutput => new CandidateSetlistOutput(
                setlistfmId: $candidate['setlistfmId'],
                eventDate: $candidate['eventDate'],
                venueName: $candidate['venueName'],
                cityName: $candidate['cityName'],
                countryCode: $candidate['countryCode'],
                tourName: $candidate['tourName'],
                songCount: $candidate['songCount'],
                isSameNight: $candidate['isSameNight'],
                url: $candidate['url'],
            ), $band['candidates']);

            return new CandidateSetlistBandOutput(
                bandId: $band['bandId'],
                bandName: $band['bandName'],
                billingOrder: $band['billingOrder'],
                recommendedSetlistfmId: $band['recommendedSetlistfmId'],
                recommendedReason: $band['recommendedReason'],
                noSetlistCause: $band['noSetlistCause'],
                candidates: $candidates,
            );
        }, $bandsJson);

        return new CandidateSetlistsOutput(
            jobId: $job->getId() ?? 0,
            expiresAt: $job->getExpiresAt() ?? throw new \LogicException('Suspended job has no expiresAt.'),
            concertId: $job->getConcert()->getId() ?? 0,
            bands: $bands,
        );
    }
}
