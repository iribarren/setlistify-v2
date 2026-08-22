<?php

declare(strict_types=1);

namespace App\State\Provider\Setlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Setlist\BandSearchCandidateOutput;
use App\ApiResource\Setlist\BandSearchOutput;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /api/band-searches?name=` (US-1). A pure passthrough onto setlist.fm's own search (AC-1.1,
 * AC-1.2) — never writes a `Band` row. Cached (AC-1.7) by `SetlistGateway`.
 *
 * @implements ProviderInterface<BandSearchOutput>
 */
final readonly class BandSearchProvider implements ProviderInterface
{
    public function __construct(
        private SetlistGateway $gateway,
        private SetlistNormalizer $normalizer,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BandSearchOutput
    {
        /** @var Request $request */
        $request = $context['request'];
        $name = trim((string) $request->query->get('name', ''));

        if ('' === $name) {
            throw new UnprocessableEntityHttpException('"name" must not be blank.');
        }

        $fetch = $this->gateway->searchArtist($name);
        $freshness = FreshnessEnvelopeMapper::from($fetch);

        if (null === $fetch->payload) {
            return new BandSearchOutput([], $freshness);
        }

        $candidates = array_map(
            static fn ($candidate): BandSearchCandidateOutput => new BandSearchCandidateOutput(
                $candidate->mbid,
                $candidate->name,
                $candidate->sortName,
                $candidate->disambiguation,
            ),
            $this->normalizer->parseArtistSearchCandidates($fetch->payload),
        );

        return new BandSearchOutput($candidates, $freshness);
    }
}
