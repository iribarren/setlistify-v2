<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use App\Entity\Band;
use App\Entity\Concert;
use App\Entity\ConcertBand;
use App\Entity\User;

/**
 * US-7/US-9: suspend/unsuspend revokes refresh tokens (AC-7.2) and is audited (AC-7.3); delete
 * cascades to owned data but not shared Band/Venue rows (AC-7.4) and the audit entry survives the
 * delete (AC-7.6); reveal-email is audited (AC-9.3) and rate-limited (AC-9.4).
 */
final class AdminUserActionsTest extends AdminWebTestCase
{
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

        $client->request('POST', '/admin/user/'.$subjectId.'/toggle-active', server: ['HTTP_ORIGIN' => self::ORIGIN]);
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

        $em = static::getContainer()->get('doctrine')->getManager();
        $subject = $em->getRepository(User::class)->findOneBy(['email' => $subjectEmail]);
        self::assertNotNull($subject);
        $subjectId = $subject->getId();

        $band = $em->getRepository(Band::class)->findOneBy(['name' => $bandName]);
        self::assertNotNull($band);
        $bandId = $band->getId();

        $concertCountBefore = \count($em->getRepository(Concert::class)->findBy(['owner' => $subject]));
        self::assertSame(1, $concertCountBefore);

        $client->request(
            'POST',
            '/admin/user/'.$subjectId.'/delete/perform',
            parameters: ['confirm_id' => (string) $subjectId],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects();

        self::assertNull($em->getRepository(User::class)->find($subjectId));
        self::assertSame([], $em->getRepository(Concert::class)->findBy(['owner' => $subjectId]));
        self::assertSame([], $em->getRepository(ConcertBand::class)->findBy(['band' => $bandId]));

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
            parameters: ['confirm_id' => 'not-the-id'],
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

        $client->request('GET', '/admin/user/'.$subject->getId().'/reveal-email');
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

        $client->request('GET', '/admin/user/'.$subject->getId().'/reveal-email');
        self::assertResponseStatusCodeSame(429);
    }
}
