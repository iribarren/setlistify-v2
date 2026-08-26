<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\UserEraser;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-243, AC-4.6: review `notes` must never appear in `AuditLogEntry.values` (this app's shape:
 * `field`/`oldValue`/`newValue`), in logs, or in any exception message. The only admin action that
 * ever touches a `ConcertReview` at all is user erasure (D-244: the FK cascade removes it) — the
 * backoffice's own `ConcertReviewCrudController` is read-only and writes no audit entry. This test
 * asserts the audit entry that erasure produces carries no trace of the notes content it deleted.
 */
final class ConcertReviewAuditExclusionTest extends ConcertReviewWebTestCase
{
    public function testErasingAUserWithASensitiveReviewLeavesNoNotesContentInTheAuditTrail(): void
    {
        $client = $this->createAuthClient();
        $auth = $this->registerAndLogin($client);
        $concert = $this->createPastConcert($client, $auth['accessToken']);

        $secret = 'This must never leak: my social security number is 123-45-6789 and I hate my boss Greg.';
        $this->putReview($client, $auth['accessToken'], self::idOf($concert), ['rating' => 1, 'notes' => $secret], Response::HTTP_CREATED);

        $em = $this->entityManager();
        /** @var User $subject */
        $subject = static::getContainer()->get(UserRepository::class)->findOneByEmail($auth['email']);
        $subjectId = $subject->getId();
        self::assertNotNull($subjectId);

        /** @var User $actor */
        $actor = static::getContainer()->get(UserRepository::class)->findOneByEmail($auth['email']);

        static::getContainer()->get(UserEraser::class)->erase($subject, $actor);

        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $subjectId, 'action' => 'delete_user']);
        self::assertNotEmpty($entries);

        foreach ($entries as $entry) {
            self::assertStringNotContainsString($secret, (string) $entry->getField());
            self::assertStringNotContainsString($secret, (string) $entry->getOldValue());
            self::assertStringNotContainsString($secret, (string) $entry->getNewValue());
            self::assertStringNotContainsString('123-45-6789', (string) $entry->getOldValue());
            self::assertStringNotContainsString('123-45-6789', (string) $entry->getNewValue());
        }
    }
}
