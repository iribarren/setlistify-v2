<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\ConcertBand;
use App\Entity\User;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * US-7/US-9: suspend/unsuspend revokes refresh tokens (AC-7.2) and is audited (AC-7.3); delete
 * cascades to owned data but not shared Band/Venue rows (AC-7.4) and the audit entry survives the
 * delete (AC-7.6); reveal-email is audited (AC-9.3) and rate-limited (AC-9.4).
 *
 * US-1/US-2/US-3 (docs/specs/2026-08-27-admin-set-email-verified.md): manual email verification is
 * verify-only (D-248), audited with a literal timestamp (D-249), and always stamped with the
 * injected clock's current time, never backdated (D-250).
 */
final class AdminUserActionsTest extends AdminWebTestCase
{
    use ClockSensitiveTrait;

    public function testConfirmVerifyEmailScreenHasNoSideEffect(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->apiRegisterAndLogin($client);
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $subject['email']]);
        self::assertNotNull($user);
        $userId = $user->getId();

        $client->request('GET', '/admin/user/'.$userId.'/verify-email/confirm');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified());
        self::assertNull($user->getEmailVerifiedAt());

        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $userId, 'action' => 'verify_email_manually']);
        self::assertCount(0, $entries);
    }

    public function testVerifyEmailSetsTimestampAndAudits(): void
    {
        $frozenNow = new \DateTimeImmutable('2026-08-27T10:00:00+00:00');
        self::mockTime($frozenNow);

        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->apiRegisterAndLogin($client);
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $subject['email']]);
        self::assertNotNull($user);
        $userId = $user->getId();
        self::assertFalse($user->isEmailVerified());

        $client->request(
            'POST',
            '/admin/user/'.$userId.'/verify-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($user);
        self::assertTrue($user->isEmailVerified());
        self::assertEquals($frozenNow, $user->getEmailVerifiedAt());

        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $userId, 'action' => 'verify_email_manually']);
        self::assertCount(1, $entries);
        self::assertSame('User', $entries[0]->getSubjectType());
        self::assertSame('emailVerifiedAt', $entries[0]->getField());
        self::assertNull($entries[0]->getOldValue());
        self::assertSame($frozenNow->format(\DateTimeInterface::ATOM), $entries[0]->getNewValue());
        self::assertNotSame($admin['email'], $entries[0]->getActorLabel());
        self::assertStringNotContainsString('@', (string) $entries[0]->getActorLabel());
    }

    public function testVerifyActionHiddenForAlreadyVerifiedUser(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->apiRegisterAndLogin($client);
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $subject['email']]);
        self::assertNotNull($user);
        $user->markEmailVerified(new \DateTimeImmutable());
        $em->flush();
        $userId = $user->getId();

        $client->request('GET', '/admin/user/'.$userId);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Verify email', (string) $client->getResponse()->getContent());
    }

    public function testVerifyRejectsAlreadyVerifiedTarget(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->apiRegisterAndLogin($client);
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $subject['email']]);
        self::assertNotNull($user);
        $alreadyVerifiedAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $user->markEmailVerified($alreadyVerifiedAt);
        $em->flush();
        $userId = $user->getId();

        $client->request(
            'POST',
            '/admin/user/'.$userId.'/verify-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseStatusCodeSame(422);

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($user);
        self::assertEquals($alreadyVerifiedAt, $user->getEmailVerifiedAt());

        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $userId, 'action' => 'verify_email_manually']);
        self::assertCount(0, $entries);
    }

    public function testVerifyEmailRejectsBadCsrf(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->apiRegisterAndLogin($client);
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $subject['email']]);
        self::assertNotNull($user);
        $userId = $user->getId();

        $client->request(
            'POST',
            '/admin/user/'.$userId.'/verify-email',
            parameters: ['_csrf_token' => 'not-the-real-token'],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseStatusCodeSame(422);

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->find($userId);
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified());

        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $userId, 'action' => 'verify_email_manually']);
        self::assertCount(0, $entries);
    }

    /** AC-1.6: `/api/me` for the subject reflects the new value, with no other change to the shape. */
    public function testVerifiedUserSeesItReflectedOnApiMe(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->apiRegisterAndLogin($client);
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $subject['email']]);
        self::assertNotNull($user);
        $userId = $user->getId();

        $client->request(
            'POST',
            '/admin/user/'.$userId.'/verify-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        $client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$subject['accessToken'],
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['emailVerified']);
        self::assertSame($subject['email'], $data['email']);
        self::assertSame(['ROLE_USER'], $data['roles']);
    }

    public function testSuspendRevokesRefreshTokensAndIsAudited(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        // A separate, ordinary user with a live refresh token (via the real API).
        $subjectEmail = self::uniqueEmail('subject');
        $subjectPassword = self::strongPassword();
        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $subjectEmail, 'password' => $subjectPassword], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $subjectEmail, 'password' => $subjectPassword], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $refreshCookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($refreshCookie);

        $em = static::getContainer()->get('doctrine')->getManager();
        $subjectRepository = $em->getRepository(User::class);
        $subject = $subjectRepository->findOneBy(['email' => $subjectEmail]);
        self::assertNotNull($subject);

        $subjectId = $subject->getId();

        $client->request(
            'POST',
            '/admin/user/'.$subjectId.'/toggle-active',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        // The kernel reboots between KernelBrowser requests, so the entity manager (and any entity
        // fetched from it) from before this request is stale — re-fetch through the current one.
        $em = static::getContainer()->get('doctrine')->getManager();
        $subject = $em->getRepository(User::class)->find($subjectId);
        self::assertNotNull($subject);
        self::assertFalse($subject->isActive());

        // The refresh token that was live before suspension must now fail.
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $refreshCookie->getValue(), null, '/api'));
        $client->request(
            'POST',
            '/api/token/refresh',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(401);

        $em = static::getContainer()->get('doctrine')->getManager();
        $auditRepository = $em->getRepository(AuditLogEntry::class);
        $entries = $auditRepository->findBy(['subjectId' => (string) $subjectId, 'action' => 'suspend_user']);
        self::assertCount(1, $entries);
        self::assertSame('isActive', $entries[0]->getField());
        self::assertSame('true', $entries[0]->getOldValue());
        self::assertSame('false', $entries[0]->getNewValue());
    }

    public function testDeleteCascadesOwnedDataButNotSharedBandAndAuditSurvives(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subjectEmail = self::uniqueEmail('erase');
        $registered = $this->apiRegisterAndLogin($client, $subjectEmail);

        $bandName = 'Erasure Test Band '.bin2hex(random_bytes(4));
        $this->apiCreateConcert($client, $registered['accessToken'], [
            'date' => '2026-12-24',
            'timezone' => 'Europe/Madrid',
            'lineup' => [['name' => $bandName]],
        ]);

        // A PAST concert + review (docs/specs/2026-08-26-notes-and-reviews.md, D-244, AC-10.2):
        // erasing the subject must take their review with it.
        $subjectPastConcert = $this->apiCreateConcert($client, $registered['accessToken'], [
            'date' => '2020-01-01',
            'timezone' => 'Europe/Madrid',
            'lineup' => [['name' => 'Erasure Review Band '.bin2hex(random_bytes(4))]],
        ]);
        self::assertIsInt($subjectPastConcert['id']);
        $this->apiPutReview($client, $registered['accessToken'], $subjectPastConcert['id'], ['rating' => 5]);

        // A bystander with their OWN past concert + review — must survive the subject's erasure.
        $bystander = $this->apiRegisterAndLogin($client);
        $bystanderConcert = $this->apiCreateConcert($client, $bystander['accessToken'], [
            'date' => '2020-01-01',
            'timezone' => 'Europe/Madrid',
            'lineup' => [['name' => 'Bystander Review Band '.bin2hex(random_bytes(4))]],
        ]);
        self::assertIsInt($bystanderConcert['id']);
        $this->apiPutReview($client, $bystander['accessToken'], $bystanderConcert['id'], ['rating' => 3]);

        $em = static::getContainer()->get('doctrine')->getManager();
        $subject = $em->getRepository(User::class)->findOneBy(['email' => $subjectEmail]);
        self::assertNotNull($subject);
        $subjectId = $subject->getId();

        $band = $em->getRepository(Band::class)->findOneBy(['name' => $bandName]);
        self::assertNotNull($band);
        $bandId = $band->getId();

        $concertCountBefore = \count($em->getRepository(Concert::class)->findBy(['owner' => $subject]));
        self::assertSame(2, $concertCountBefore);

        $bystanderUser = $em->getRepository(User::class)->findOneBy(['email' => $bystander['email']]);
        self::assertNotNull($bystanderUser);
        $bystanderReviewCountBefore = \count($em->getRepository(\App\Entity\ConcertReview::class)->findBy(['owner' => $bystanderUser]));
        self::assertSame(1, $bystanderReviewCountBefore);

        $client->request(
            'POST',
            '/admin/user/'.$subjectId.'/delete/perform',
            parameters: ['confirm_id' => (string) $subjectId, '_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        self::assertNull($em->getRepository(User::class)->find($subjectId));
        self::assertSame([], $em->getRepository(Concert::class)->findBy(['owner' => $subjectId]));
        self::assertSame([], $em->getRepository(ConcertBand::class)->findBy(['band' => $bandId]));

        // D-244/AC-10.2: the subject's review is gone (cascaded via both owner_id and concert_id),
        // and the bystander's review, on their own concert, survives untouched.
        self::assertSame([], $em->getRepository(\App\Entity\ConcertReview::class)->findBy(['owner' => $subjectId]));
        self::assertSame(1, \count($em->getRepository(\App\Entity\ConcertReview::class)->findBy(['owner' => $bystanderUser])));

        // Band survives — shared, not user-scoped (AC-7.4).
        self::assertNotNull($em->getRepository(Band::class)->find($bandId));

        // Audit entry survives the delete it describes (AC-7.6) and carries no plaintext PII.
        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $subjectId, 'action' => 'delete_user']);
        self::assertCount(1, $entries);
        self::assertStringNotContainsString('@', (string) $entries[0]->getOldValue());
    }

    public function testDeleteRequiresTypingTheCorrectId(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $em = static::getContainer()->get('doctrine')->getManager();
        $target = $this->createAdmin()['user'];
        $em->persist($target);
        $em->flush();

        $client->request(
            'POST',
            '/admin/user/'.$target->getId().'/delete/perform',
            parameters: ['confirm_id' => 'not-the-id', '_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseStatusCodeSame(422);

        self::assertNotNull($em->getRepository(User::class)->find($target->getId()));
    }

    public function testRevealEmailIsAuditedAndRateLimited(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->createAdmin()['user'];
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($subject);
        $em->flush();

        $client->request(
            'POST',
            '/admin/user/'.$subject->getId().'/reveal-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($subject->getEmail(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $subject->getId(), 'action' => 'reveal_email']);
        self::assertCount(1, $entries);

        // AC-9.4: 30/hour. One reveal already happened via HTTP above; exhaust the remaining 29
        // directly against the limiter (cheaper than 29 more full HTTP+DomCrawler round trips),
        // then a real HTTP request for the 31st must be rejected.
        $limiter = static::getContainer()->get('limiter.admin_reveal_email');
        $adminActorId = (string) $admin['user']->getId();
        for ($i = 0; $i < 29; ++$i) {
            $limiter->create($adminActorId)->consume();
        }

        $client->request(
            'POST',
            '/admin/user/'.$subject->getId().'/reveal-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * CSRF/method regression (devops-security-engineer review, 2026-08-21): reveal-email was
     * originally a plain GET route with no CSRF check — a sensitive, audited action triggerable by a
     * simple link click, including a cross-site one (a top-level GET navigation still carries the
     * `SameSite=Lax` admin cookie). It is now POST + CSRF-protected exactly like suspend/delete.
     */
    public function testRevealEmailRejectsGetAndCrossOriginPost(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $subject = $this->createAdmin()['user'];
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->persist($subject);
        $em->flush();

        $client->request('GET', '/admin/user/'.$subject->getId().'/reveal-email');
        self::assertResponseStatusCodeSame(405);

        $client->request(
            'POST',
            '/admin/user/'.$subject->getId().'/reveal-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/attack'],
        );
        self::assertResponseStatusCodeSame(422);

        $em->clear();
        $entries = $em->getRepository(AuditLogEntry::class)->findBy(['subjectId' => (string) $subject->getId(), 'action' => 'reveal_email']);
        self::assertCount(0, $entries);
    }
}
