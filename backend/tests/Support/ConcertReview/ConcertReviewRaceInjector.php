<?php

declare(strict_types=1);

namespace App\Tests\Support\ConcertReview;

use App\Entity\ConcertReview;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Event\PrePersistEventArgs;

/**
 * AC-3.4: PHPUnit runs single-threaded — there is no second process to genuinely race against — so
 * this simulates one deterministically, from INSIDE the real request/response cycle (unlike
 * `App\Tests\Functional\Concert\BandDedupTest`'s equivalent gap, which settles for the direct
 * unique-constraint test alone because nothing in that flow needs to survive a kernel reboot to
 * prove it).
 *
 * `App\Tests\Functional\ConcertReview\ConcertReviewConcurrencyTest` arms this with the exact
 * owner/concert pair under test, then issues a real `PUT`. On the FIRST `prePersist` of a
 * `ConcertReview` for that pair — i.e. exactly where `App\State\Processor\ConcertReviewPutProcessor
 * ::createRaceSafe()` is about to insert its own row — this raw-SQL-inserts a "concurrent winner"
 * row first — over a SEPARATE, immediately-committed connection, so it survives even if the
 * processor's own attempt then rolls back its savepoint (using the SAME connection as the one this
 * fires from would undo the "concurrent" row right along with it) — so the processor's own insert
 * collides against a real `UniqueConstraintViolationException` and must take its catch-and-retry
 * path for real. Inert (a no-op) unless `arm()` was called; every other test's writes pass through
 * untouched.
 *
 * State is deliberately `static`, not instance state: `Symfony\Bundle\FrameworkBundle\Test\
 * KernelBrowser` rebuilds the whole container (a fresh instance of this class included) on every
 * `$client->request()` call, so any state set on an instance fetched BEFORE the request is gone by
 * the time the request's own `prePersist` fires on the NEW instance — a `static` property is the
 * only thing that survives that reboot within the same PHP process.
 */
final class ConcertReviewRaceInjector
{
    private static bool $armed = false;
    private static ?int $ownerId = null;
    private static ?int $concertId = null;
    private static bool $fired = false;

    public function arm(int $ownerId, int $concertId): void
    {
        self::$armed = true;
        self::$ownerId = $ownerId;
        self::$concertId = $concertId;
        self::$fired = false;
    }

    public function disarm(): void
    {
        self::$armed = false;
        self::$fired = false;
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!self::$armed || self::$fired || !$entity instanceof ConcertReview) {
            return;
        }

        if ($entity->getOwner()->getId() !== self::$ownerId || $entity->getConcert()->getId() !== self::$concertId) {
            return;
        }

        self::$fired = true;

        // A second, independent connection — not `$args->getObjectManager()->getConnection()` —
        // so this insert is committed for real and survives the processor's own savepoint rollback
        // (see class docblock).
        $params = $args->getObjectManager()->getConnection()->getParams();
        $sideConnection = DriverManager::getConnection($params);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $sideConnection->executeStatement(
            'INSERT INTO concert_reviews (owner_id, concert_id, rating, notes, created_at, updated_at) VALUES (:owner, :concert, :rating, :notes, :now, :now)',
            ['owner' => self::$ownerId, 'concert' => self::$concertId, 'rating' => 1, 'notes' => 'Concurrent winner', 'now' => $now],
        );

        $sideConnection->close();
    }
}
