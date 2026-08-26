<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use App\Entity\Concert;
use App\Entity\ConcertReview;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * AC-3.3: `UNIQUE (owner_id, concert_id)` is the backstop even though the endpoint shape makes it
 * unreachable through the API — a test writes two rows for the same pair directly through the
 * entity manager and asserts the constraint fires.
 */
final class ConcertReviewUniqueConstraintTest extends ConcertReviewWebTestCase
{
    public function testTwoReviewsForTheSameOwnerAndConcertViolateTheUniqueIndex(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concertData = $this->createPastConcert($client, $auth['accessToken']);

        $em = $this->entityManager();
        /** @var User $owner */
        $owner = static::getContainer()->get(UserRepository::class)->findOneByEmail($auth['email']);
        /** @var Concert $concert */
        $concert = $em->getRepository(Concert::class)->find(self::idOf($concertData));

        $now = new \DateTimeImmutable();
        $first = new ConcertReview($owner, $concert, $now);
        $first->apply(4, 'First row', null, null, $now);
        $em->persist($first);
        $em->flush();

        $second = new ConcertReview($owner, $concert, $now);
        $second->apply(2, 'Second row — must collide', null, null, $now);
        $em->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }
}
