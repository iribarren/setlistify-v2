<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Entity\Band;

/**
 * US-4: raw setlist.fm JSON → `Setlist`/`Song` entities, including covers, tape and encores
 * (AC-4.2, AC-4.3) and the empty-setlist case (AC-4.4).
 */
final class SetlistNormalizerTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testHydratesCoversTapeAndEncoresWithoutFilteringAnySong(): void
    {
        $payload = json_decode(self::fixture('setlist-detail-covers-tape-encores.json'), true, flags: \JSON_THROW_ON_ERROR);
        $payload['id'] = self::uniqueSetlistfmId();
        $band = $this->persistBand(self::uniqueBandName('Nirvana'), self::uniqueMbid());

        $setlist = $this->normalizer()->hydrateSetlistDetail($band, $payload, new \DateTimeImmutable());

        self::assertSame($payload['id'], $setlist->getSetlistfmId());
        self::assertFalse($setlist->isEmpty());
        self::assertSame(8, $setlist->getSongCount());

        $songs = $setlist->getSongs()->toArray();
        self::assertSame(0, $songs[0]->getPosition());
        self::assertNull($songs[0]->getSetLabel());

        $cover = $songs[4];
        self::assertSame('The Man Who Sold the World', $cover->getTitle());
        self::assertSame('David Bowie', $cover->getCoverOfName());
        self::assertSame('Encore 1', $cover->getSetLabel());

        $withGuest = $songs[5];
        self::assertSame('Pat Smear', $withGuest->getWithName());

        $tape = $songs[7];
        self::assertTrue($tape->isTape());
        self::assertSame('Encore 2', $tape->getSetLabel());
    }

    public function testHydratesAnEmptySetlistAsExplicitlyEmptyNotAsMissing(): void
    {
        $payload = json_decode(self::fixture('setlist-detail-empty.json'), true, flags: \JSON_THROW_ON_ERROR);
        $payload['id'] = self::uniqueSetlistfmId();
        $band = $this->persistBand(self::uniqueBandName('Nirvana Empty Show'), self::uniqueMbid());

        $setlist = $this->normalizer()->hydrateSetlistDetail($band, $payload, new \DateTimeImmutable());

        self::assertTrue($setlist->isEmpty());
        self::assertSame(0, $setlist->getSongCount());
        self::assertSame($payload['id'], $setlist->getSetlistfmId());
    }

    public function testHydratingTheSameSetlistfmIdTwiceReturnsTheSameEntityWithoutReparsing(): void
    {
        $payload = json_decode(self::fixture('setlist-detail-covers-tape-encores.json'), true, flags: \JSON_THROW_ON_ERROR);
        $payload['id'] = self::uniqueSetlistfmId();
        $band = $this->persistBand(self::uniqueBandName('Nirvana Idempotent'), self::uniqueMbid());

        $first = $this->normalizer()->hydrateSetlistDetail($band, $payload, new \DateTimeImmutable());
        $second = $this->normalizer()->hydrateSetlistDetail($band, $payload, new \DateTimeImmutable());

        self::assertSame($first->getId(), $second->getId());
    }

    private function persistBand(string $name, string $mbid): Band
    {
        $band = new Band($name, \App\Service\Concert\BandResolver::normalize($name), new \DateTimeImmutable());
        $band->resolveTo($mbid, $name, new \DateTimeImmutable());
        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    private static function uniqueBandName(string $label): string
    {
        return \sprintf('%s %s', $label, bin2hex(random_bytes(6)));
    }

    private static function uniqueMbid(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function uniqueSetlistfmId(): string
    {
        return bin2hex(random_bytes(4));
    }
}
