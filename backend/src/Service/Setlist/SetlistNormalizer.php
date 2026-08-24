<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use App\Entity\Band;
use App\Entity\Setlist;
use App\Entity\Song;
use App\Repository\SetlistRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Raw setlist.fm JSON → `Setlist`/`Song` entities (D-60). Reads are idempotent: an already-known
 * `setlistfmId` is returned as-is rather than re-parsed, since a past setlist never changes
 * (D-59) — this is what makes `app:setlist:refresh` safe to run twice (AC-10.8).
 */
final readonly class SetlistNormalizer
{
    public function __construct(
        private SetlistRepository $setlistRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload setlist.fm's `/search/artists` response
     *
     * @return list<ArtistSearchCandidate>
     */
    public function parseArtistSearchCandidates(array $payload): array
    {
        $candidates = [];
        /** @var array<string, mixed> $raw */
        foreach ((array) ($payload['artist'] ?? []) as $raw) {
            $mbid = $raw['mbid'] ?? null;
            $name = $raw['name'] ?? null;
            if (!\is_string($mbid) || '' === $mbid || !\is_string($name) || '' === $name) {
                continue;
            }

            $candidates[] = new ArtistSearchCandidate(
                mbid: $mbid,
                name: $name,
                sortName: \is_string($raw['sortName'] ?? null) ? $raw['sortName'] : null,
                disambiguation: \is_string($raw['disambiguation'] ?? null) && '' !== $raw['disambiguation'] ? $raw['disambiguation'] : null,
                url: \is_string($raw['url'] ?? null) ? $raw['url'] : null,
            );
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $payload setlist.fm's `/artist/{mbid}/setlists` response
     *
     * @return array{setlists: list<Setlist>, total: int, page: int, itemsPerPage: int}
     */
    public function hydrateSetlistsPage(Band $band, array $payload, \DateTimeImmutable $fetchedAt): array
    {
        $hydrated = [];
        /** @var array<string, mixed> $raw */
        foreach ((array) ($payload['setlist'] ?? []) as $raw) {
            $hydrated[] = $this->hydrateOne($band, $raw, $fetchedAt);
        }

        return [
            'setlists' => $hydrated,
            'total' => self::asInt($payload['total'] ?? null, \count($hydrated)),
            'page' => self::asInt($payload['page'] ?? null, 1),
            'itemsPerPage' => self::asInt($payload['itemsPerPage'] ?? null, \count($hydrated)),
        ];
    }

    /** @param array<string, mixed> $payload setlist.fm's `/setlist/{id}` response */
    public function hydrateSetlistDetail(Band $band, array $payload, \DateTimeImmutable $fetchedAt): Setlist
    {
        return $this->hydrateOne($band, $payload, $fetchedAt);
    }

    /** @param array<string, mixed> $raw */
    private function hydrateOne(Band $band, array $raw, \DateTimeImmutable $fetchedAt): Setlist
    {
        $setlistfmId = self::asString($raw['id'] ?? null);
        \assert('' !== $setlistfmId, 'setlist.fm setlist payload missing "id"');

        $existing = $this->setlistRepository->findOneBySetlistfmId($setlistfmId);
        if (null !== $existing) {
            // D-59: immutable once fetched — never re-parsed, never re-written.
            return $existing;
        }

        $venue = self::asArray($raw['venue'] ?? null);
        $city = self::asArray($venue['city'] ?? null);
        $country = self::asArray($city['country'] ?? null);
        $tour = self::asArray($raw['tour'] ?? null);

        $setlist = new Setlist(
            setlistfmId: $setlistfmId,
            band: $band,
            eventDate: $this->parseEventDate(self::asString($raw['eventDate'] ?? null)),
            venueName: \is_string($venue['name'] ?? null) ? $venue['name'] : null,
            venueCity: \is_string($city['name'] ?? null) ? $city['name'] : null,
            venueCountry: \is_string($country['code'] ?? null) ? $country['code'] : null,
            tourName: \is_string($tour['name'] ?? null) && '' !== $tour['name'] ? $tour['name'] : null,
            fetchedAt: $fetchedAt,
            url: \is_string($raw['url'] ?? null) ? $raw['url'] : null,
        );

        $position = 0;
        $setsContainer = self::asArray($raw['sets'] ?? null);
        $sets = self::asArray($setsContainer['set'] ?? null);
        foreach ($sets as $set) {
            $set = self::asArray($set);
            $setLabel = $this->setLabel($set);
            $songs = self::asArray($set['song'] ?? null);
            foreach ($songs as $songRaw) {
                $songRaw = self::asArray($songRaw);
                $title = self::asString($songRaw['name'] ?? null);
                if ('' === $title) {
                    continue;
                }

                $cover = self::asArray($songRaw['cover'] ?? null);
                $with = self::asArray($songRaw['with'] ?? null);

                $song = new Song(
                    setlist: $setlist,
                    position: $position,
                    setLabel: $setLabel,
                    title: $title,
                    coverOfName: \is_string($cover['name'] ?? null) ? $cover['name'] : null,
                    coverOfMbid: \is_string($cover['mbid'] ?? null) ? $cover['mbid'] : null,
                    withName: \is_string($with['name'] ?? null) ? $with['name'] : null,
                    info: \is_string($songRaw['info'] ?? null) && '' !== $songRaw['info'] ? $songRaw['info'] : null,
                    isTape: true === ($songRaw['tape'] ?? false),
                );
                $setlist->addSong($song);
                ++$position;
            }
        }

        if (0 === $position) {
            $setlist->markEmpty(); // AC-4.4
        }

        $this->entityManager->persist($setlist);
        $this->entityManager->flush();

        return $setlist;
    }

    /** @param array<int|string, mixed> $set */
    private function setLabel(array $set): ?string
    {
        if (isset($set['encore']) && is_numeric($set['encore'])) {
            return \sprintf('Encore %d', (int) $set['encore']);
        }

        if (\is_string($set['name'] ?? null) && '' !== $set['name']) {
            return $set['name'];
        }

        return null;
    }

    private function parseEventDate(string $eventDate): \DateTimeImmutable
    {
        // setlist.fm's own format: dd-mm-YYYY.
        $parsed = \DateTimeImmutable::createFromFormat('!d-m-Y', $eventDate, new \DateTimeZone('UTC'));

        return false !== $parsed ? $parsed : new \DateTimeImmutable('1970-01-01', new \DateTimeZone('UTC'));
    }

    /** Narrows an arbitrary decoded-JSON value to a string, or `''` if it isn't one. */
    private static function asString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** Narrows an arbitrary decoded-JSON value to an int, or `$default` if it isn't numeric. */
    private static function asInt(mixed $value, int $default): int
    {
        return \is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Narrows an arbitrary decoded-JSON value to an array, or `[]` if it isn't one — setlist.fm's
     * JSON always decodes nested objects/lists to PHP arrays (no `JSON_OBJECT_AS_ARRAY` opt-out),
     * so this is the one place every "walk into a nested field" step in this class routes through.
     *
     * @return array<int|string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
