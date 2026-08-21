<?php

declare(strict_types=1);

namespace App\Tests\Functional\Concert;

use App\Entity\Band;
use App\Service\Concert\BandResolver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-8, AC-11.4: band dedup across two users and across spellings that normalize alike, plus a
 * direct constraint-violation test for AC-8.5 (the "or" alternative to a true concurrency test —
 * see this file's last test method for why a genuine two-process race isn't simulated here).
 */
final class BandDedupTest extends ConcertWebTestCase
{
    public function testTwoUsersTypingTheSameBandNameShareOneRow(): void
    {
        $client = $this->createAuthClient();
        $userA = $this->registerAndLogin($client);
        $userB = $this->registerAndLogin($client);

        $concertA = $this->createConcert($client, $userA['accessToken'], [
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => 'Radiohead']],
        ]);
        $concertB = $this->createConcert($client, $userB['accessToken'], [
            'date' => '2026-02-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => 'Radiohead']],
        ]);

        self::assertSame(self::bandIdOf(self::lineupEntryAt($concertA, 0)), self::bandIdOf(self::lineupEntryAt($concertB, 0)));
    }

    public function testSpellingsThatNormalizeAlikeShareOneRowAndKeepTheFirstSpelling(): void
    {
        $client = $this->createAuthClient();
        $userA = $this->registerAndLogin($client);
        $userB = $this->registerAndLogin($client);

        $unique = bin2hex(random_bytes(4));

        $first = $this->createConcert($client, $userA['accessToken'], [
            'date' => '2026-01-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => "The Motörhead {$unique}"]],
        ]);
        $second = $this->createConcert($client, $userB['accessToken'], [
            'date' => '2026-02-01',
            'timezone' => 'UTC',
            'lineup' => [['name' => "motorhead {$unique}"]],
        ]);

        $firstEntry = self::lineupEntryAt($first, 0);
        $secondEntry = self::lineupEntryAt($second, 0);

        self::assertSame(self::bandIdOf($firstEntry), self::bandIdOf($secondEntry));
        self::assertSame("The Motörhead {$unique}", self::bandNameOf($secondEntry), 'AC-8.4: the canonical name is the first creator\'s spelling, not overwritten by a later one');
    }

    public function testDeletingOneUsersConcertDoesNotAffectAnotherUsersSharedBand(): void
    {
        $client = $this->createAuthClient();
        $userA = $this->registerAndLogin($client);
        $userB = $this->registerAndLogin($client);

        $bandName = self::uniqueBandName('Shared');
        $concertA = $this->createConcert($client, $userA['accessToken'], self::minimalConcertPayload(bandName: $bandName));
        $concertB = $this->createConcert($client, $userB['accessToken'], self::minimalConcertPayload('2026-03-01', bandName: $bandName));

        $client->request('DELETE', \sprintf('/api/concerts/%d', self::idOf($concertA)), server: self::authHeaders($userA['accessToken']));
        self::assertResponseIsSuccessful();

        $client->request('GET', \sprintf('/api/concerts/%d', self::idOf($concertB)), server: self::authHeaders($userB['accessToken']));
        self::assertResponseIsSuccessful();
        $stillThere = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame($bandName, self::bandNameOf(self::lineupEntryAt($stillThere, 0)));
    }

    /**
     * AC-8.5's arbiter: `bands.normalized_name`'s unique index is what `BandResolver::resolve()`'s
     * catch block relies on to turn a lost race into a re-read instead of a 500. A genuine two-
     * process race can't be expressed inside a single-threaded PHPUnit run without process forking
     * (fragile in a container, and not worth the flakiness) — this is the "direct constraint-
     * violation test" AC-11.4 offers as the alternative: it proves the constraint the resolver
     * depends on actually exists and actually rejects a duplicate, which is the part of AC-8.5 that
     * would silently stop being true if a future migration ever dropped or narrowed that index.
     */
    public function testTheUniqueConstraintBandResolverDependsOnActuallyRejectsADuplicate(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $normalized = BandResolver::normalize('Constraint Test Band '.bin2hex(random_bytes(4)));

        $first = new Band('Constraint Test Band', $normalized, new \DateTimeImmutable());
        $em->persist($first);
        $em->flush();

        $second = new Band('Constraint Test Band (again)', $normalized, new \DateTimeImmutable());
        $em->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }
}
