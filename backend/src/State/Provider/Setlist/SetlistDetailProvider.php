<?php

declare(strict_types=1);

namespace App\State\Provider\Setlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Setlist\FreshnessEnvelope;
use App\ApiResource\Setlist\SetlistDetailOutput;
use App\ApiResource\Setlist\SongOutput;
use App\Entity\Band;
use App\Entity\Setlist;
use App\Repository\BandRepository;
use App\Repository\SetlistRepository;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use Psr\Clock\ClockInterface;

/**
 * `GET /api/setlists/{setlistfmId}` (US-4). Once fetched, a setlist is immutable (D-59) — the
 * durable tier is checked directly before any outbound attempt, so a repeated read of the same
 * setlist never touches setlist.fm again (AC-4.5).
 *
 * @implements ProviderInterface<SetlistDetailOutput>
 */
final readonly class SetlistDetailProvider implements ProviderInterface
{
    public function __construct(
        private SetlistRepository $setlistRepository,
        private SetlistGateway $gateway,
        private SetlistNormalizer $normalizer,
        private BandRepository $bandRepository,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SetlistDetailOutput
    {
        $setlistfmId = \is_string($uriVariables['setlistfmId'] ?? null) ? $uriVariables['setlistfmId'] : '';

        $existing = $this->setlistRepository->findOneBySetlistfmId($setlistfmId);
        if ($existing instanceof Setlist) {
            return $this->fromEntity($existing, FreshnessEnvelope::fresh('cache', $existing->getFetchedAt()));
        }

        $fetch = $this->gateway->fetchSetlistDetail($setlistfmId);

        if ($fetch->notFound) {
            return new SetlistDetailOutput('not_found', null, null, null, null, null, null, false, [], FreshnessEnvelope::fresh('live', null));
        }

        $freshness = FreshnessEnvelopeMapper::from($fetch);

        if (null === $fetch->payload) {
            return new SetlistDetailOutput('unavailable', null, null, null, null, null, null, false, [], $freshness);
        }

        /** @var array<string, mixed> $artist */
        $artist = (array) ($fetch->payload['artist'] ?? []);
        $mbid = \is_string($artist['mbid'] ?? null) ? $artist['mbid'] : null;
        $band = null !== $mbid ? $this->bandRepository->findOneBy(['setlistfmMbid' => $mbid]) : null;

        if ($band instanceof Band) {
            // AC-4.6/D-60: persisted relationally so the same setlist is a durable-tier hit next time.
            $setlist = $this->normalizer->hydrateSetlistDetail($band, $fetch->payload, $fetch->fetchedAt ?? \DateTimeImmutable::createFromInterface($this->clock->now()));

            return $this->fromEntity($setlist, $freshness);
        }

        // The band behind this setlist has never been resolved through our own flow (reachable
        // only if a caller already knew a setlistfmId for a band it hasn't queried via
        // GET /api/bands/{bandId}/setlists) — answer from the raw payload without a Band FK to
        // attach a relational row to, rather than fail the read.
        return $this->fromRawPayload($fetch->payload, $freshness);
    }

    private function fromEntity(Setlist $setlist, FreshnessEnvelope $freshness): SetlistDetailOutput
    {
        $songs = array_values(array_map(
            static fn ($song): SongOutput => new SongOutput(
                $song->getPosition(),
                $song->getSetLabel(),
                $song->getTitle(),
                $song->getCoverOfName(),
                $song->getCoverOfMbid(),
                $song->getWithName(),
                $song->getInfo(),
                $song->isTape(),
            ),
            $setlist->getSongs()->toArray(),
        ));

        return new SetlistDetailOutput(
            'found',
            $setlist->getSetlistfmId(),
            $setlist->getEventDate()->format('Y-m-d'),
            $setlist->getVenueName(),
            $setlist->getVenueCity(),
            $setlist->getVenueCountry(),
            $setlist->getTourName(),
            $setlist->isEmpty(),
            $songs,
            $freshness,
        );
    }

    /** @param array<string, mixed> $payload */
    private function fromRawPayload(array $payload, FreshnessEnvelope $freshness): SetlistDetailOutput
    {
        $venue = self::toArray($payload['venue'] ?? null);
        $city = self::toArray($venue['city'] ?? null);
        $country = self::toArray($city['country'] ?? null);
        $tour = self::toArray($payload['tour'] ?? null);

        $songs = [];
        $position = 0;
        $setsContainer = self::toArray($payload['sets'] ?? null);
        foreach (self::toArray($setsContainer['set'] ?? null) as $set) {
            $set = self::toArray($set);
            $setLabel = isset($set['encore']) && is_numeric($set['encore'])
                ? \sprintf('Encore %d', (int) $set['encore'])
                : (\is_string($set['name'] ?? null) && '' !== $set['name'] ? $set['name'] : null);

            foreach (self::toArray($set['song'] ?? null) as $songRaw) {
                $songRaw = self::toArray($songRaw);
                $title = \is_string($songRaw['name'] ?? null) ? $songRaw['name'] : '';
                if ('' === $title) {
                    continue;
                }
                $cover = self::toArray($songRaw['cover'] ?? null);
                $with = self::toArray($songRaw['with'] ?? null);

                $songs[] = new SongOutput(
                    $position,
                    $setLabel,
                    $title,
                    \is_string($cover['name'] ?? null) ? $cover['name'] : null,
                    \is_string($cover['mbid'] ?? null) ? $cover['mbid'] : null,
                    \is_string($with['name'] ?? null) ? $with['name'] : null,
                    \is_string($songRaw['info'] ?? null) && '' !== $songRaw['info'] ? $songRaw['info'] : null,
                    true === ($songRaw['tape'] ?? false),
                );
                ++$position;
            }
        }

        return new SetlistDetailOutput(
            'found',
            \is_string($payload['id'] ?? null) ? $payload['id'] : null,
            \is_string($payload['eventDate'] ?? null) ? $payload['eventDate'] : null,
            \is_string($venue['name'] ?? null) ? $venue['name'] : null,
            \is_string($city['name'] ?? null) ? $city['name'] : null,
            \is_string($country['code'] ?? null) ? $country['code'] : null,
            \is_string($tour['name'] ?? null) && '' !== $tour['name'] ? $tour['name'] : null,
            0 === $position,
            $songs,
            $freshness,
        );
    }

    /** @return array<int|string, mixed> */
    private static function toArray(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
