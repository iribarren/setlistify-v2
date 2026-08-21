<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** AC-12.4: an {@see AuditLogEntry} can never be updated or deleted through the ORM. */
final class AuditLogAppendOnlyTest extends KernelTestCase
{
    public function testUpdateIsRejected(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entry = $this->persistOneEntry($em);

        $reflection = new \ReflectionProperty(AuditLogEntry::class, 'action');
        $reflection->setValue($entry, 'tampered_action');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');
        $em->flush();
    }

    public function testDeleteIsRejected(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $entry = $this->persistOneEntry($em);

        // Doctrine ORM dispatches `preRemove` synchronously from `EntityManager::remove()` itself
        // for a root entity with no cascading associations — not deferred to `flush()` — so the
        // exception surfaces here, not on the flush call below.
        try {
            $em->remove($entry);
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertStringContainsString('append-only', $e->getMessage());
        }
    }

    private function persistOneEntry(object $em): AuditLogEntry
    {
        $entry = new AuditLogEntry(
            occurredAt: new \DateTimeImmutable(),
            actorId: 1,
            actorLabel: str_repeat('a', 32),
            action: 'reveal_email',
            subjectType: 'User',
            subjectId: '1',
            field: null,
            oldValue: null,
            newValue: null,
            ipAddress: '127.0.0.1',
            userAgent: 'phpunit',
        );

        $em->persist($entry);
        $em->flush();

        return $entry;
    }
}
