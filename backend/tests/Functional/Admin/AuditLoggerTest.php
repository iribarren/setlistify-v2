<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Service\Admin\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Step 2 of the suggested implementation order: the audit trail exists, and is tested, before the
 * first real caller (suspend/delete/reveal-email) ever writes to it (AC-12.1, AC-12.6).
 */
final class AuditLoggerTest extends KernelTestCase
{
    public function testLogWritesAllDeclaredFields(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get('doctrine')->getManager();
        $user = new User(\sprintf('actor.%s@example.test', Uuid::v4()), 'placeholder-hash');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        $logger = $container->get(AuditLogger::class);
        \assert($logger instanceof AuditLogger);

        $subjectId = random_int(1, \PHP_INT_MAX);

        $logger->log(
            actor: $user,
            action: 'suspend_user',
            subjectType: 'User',
            subjectId: $subjectId,
            field: 'isActive',
            oldValue: 'true',
            newValue: 'false',
        );

        $repository = $em->getRepository(AuditLogEntry::class);

        $entries = $repository->findBy(['subjectId' => (string) $subjectId]);
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame($user->getId(), $entry->getActorId());
        self::assertNotSame($user->getEmail(), $entry->getActorLabel(), 'actorLabel must never be the plaintext email (D-43)');
        self::assertSame(32, \strlen($entry->getActorLabel()));
        self::assertSame('suspend_user', $entry->getAction());
        self::assertSame('User', $entry->getSubjectType());
        self::assertSame((string) $subjectId, $entry->getSubjectId());
        self::assertSame('isActive', $entry->getField());
        self::assertSame('true', $entry->getOldValue());
        self::assertSame('false', $entry->getNewValue());
        self::assertNotNull($entry->getOccurredAt());
    }

    public function testDigestIsStableAndNonReversible(): void
    {
        self::bootKernel();
        $logger = static::getContainer()->get(AuditLogger::class);
        \assert($logger instanceof AuditLogger);

        $digest1 = $logger->digest('someone@example.test');
        $digest2 = $logger->digest('someone@example.test');
        $digest3 = $logger->digest('someone.else@example.test');

        self::assertSame($digest1, $digest2, 'same input must always produce the same digest');
        self::assertNotSame($digest1, $digest3);
        self::assertStringNotContainsString('someone', $digest1);
    }
}
