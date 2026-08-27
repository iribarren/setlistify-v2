<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use Symfony\Component\HttpFoundation\Response;

/**
 * US-1/US-3: the admin door and the API door authenticate nothing for each other, in both
 * directions (AC-1.1, AC-3.2, AC-3.3, AC-3.4).
 */
final class AdminAccessControlTest extends AdminWebTestCase
{
    public function testUnauthenticatedDashboardRedirectsToLogin(): void
    {
        $client = $this->createAdminClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testValidApiJwtDoesNotAuthenticateAdminDashboard(): void
    {
        $client = $this->createAdminClient();
        $token = $this->apiAccessToken($client);

        $client->request('GET', '/admin', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    public function testAdminSessionCookieDoesNotAuthenticateApiMe(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        // No bearer token — only whatever session cookie the admin login left behind.
        $client->request('GET', '/api/me', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRegisteredUserHasOnlyRoleUserAndCannotReachAdmin(): void
    {
        $client = $this->createAdminClient();
        $token = $this->apiAccessToken($client);

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertSame(['ROLE_USER'], $me['roles'] ?? null);

        $client->request('GET', '/admin', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * AC-3.2 (docs/specs/2026-08-27-admin-set-email-verified.md): the manual-verify confirm and
     * perform routes are unreachable without an authenticated admin session, exactly like the
     * existing suspend/reveal-email/delete routes.
     */
    public function testVerifyEmailRoutesUnreachableWithoutAdminSession(): void
    {
        $client = $this->createAdminClient();

        $client->request('GET', '/admin/user/1/verify-email/confirm');
        self::assertResponseRedirects('/admin/login');

        $client->request(
            'POST',
            '/admin/user/1/verify-email',
            parameters: ['_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects('/admin/login');
    }
}
