<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use OTPHP\TOTP;

/**
 * US-5/D-49: a password alone never reaches the dashboard or any CRUD route (AC-5.5); an
 * unenrolled admin account can reach only the enrollment route; a full login+2FA cycle reaches the
 * dashboard.
 */
final class AdminTwoFactorTest extends AdminWebTestCase
{
    public function testPasswordAloneDoesNotReachDashboard(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();

        // Enroll first so we're testing the *normal* 2FA gate, not the forced-enrollment redirect.
        $this->loginAndEnroll($client, $admin['email'], $admin['password']);
        $client->request('POST', '/admin/logout');

        // Fresh session: password only, no TOTP code submitted. The login form's own success
        // redirect always targets `default_target_path` regardless of 2FA state (that's just
        // form_login's plain success handler) — the actual 2FA gate is enforced on the *next*
        // request to a protected resource, asserted below.
        $this->postAdminLogin($client, $admin['email'], $admin['password']);
        self::assertResponseRedirects();

        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
        self::assertNotSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/admin/user');
        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    public function testUnenrolledAdminCanReachOnlyEnrollment(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();

        $this->postAdminLogin($client, $admin['email'], $admin['password']);
        self::assertResponseRedirects();

        // The dashboard is not reachable while enrollment is pending — ForceTwoFactorEnrollmentSubscriber
        // redirects everything but the enrollment route itself.
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa-setup');

        $client->request('GET', '/admin/2fa-setup');
        self::assertResponseIsSuccessful();
    }

    public function testFullLoginAndEnrollmentReachesDashboard(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();

        $this->loginAndEnroll($client, $admin['email'], $admin['password']);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function testWrongTotpCodeDoesNotAuthenticate(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();

        $this->postAdminLogin($client, $admin['email'], $admin['password']);

        $client->request('GET', '/admin/2fa-setup');
        $html = (string) $client->getResponse()->getContent();
        \preg_match('/<p class="secret">([A-Z2-7]+)<\/p>/', $html, $matches);
        self::assertArrayHasKey(1, $matches);
        $secret = $matches[1] ?? throw new \LogicException('unreachable — asserted above');

        // A code that does not match the real (freshly-generated, unguessable) secret.
        $correctCode = TOTP::createFromSecret($secret)->now();
        $wrongCode = '000000' === $correctCode ? '111111' : '000000';

        $client->request(
            'POST',
            '/admin/2fa-setup/confirm',
            parameters: ['code' => $wrongCode, '_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', '/admin');
        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * CSRF regression (devops-security-engineer review, 2026-08-21): this bespoke, non-form_login
     * POST route previously had no CSRF check at all — a cross-origin POST with a valid TOTP code
     * completed enrollment. Verified live against a running stack before this fix landed.
     */
    public function testCrossOriginConfirmIsRejected(): void
    {
        $client = $this->createAdminClient();
        $admin = $this->createAdmin();

        $this->postAdminLogin($client, $admin['email'], $admin['password']);

        $client->request('GET', '/admin/2fa-setup');
        $html = (string) $client->getResponse()->getContent();
        \preg_match('/<p class="secret">([A-Z2-7]+)<\/p>/', $html, $matches);
        self::assertArrayHasKey(1, $matches);
        $secret = $matches[1] ?? throw new \LogicException('unreachable — asserted above');
        $code = TOTP::createFromSecret($secret)->now();

        // Symfony's SameOriginCsrfTokenManager accepts the request if EITHER Origin OR Referer
        // reflects the app's own host (OWASP's documented fallback for browsers that omit Origin
        // on same-origin requests). BrowserKit's client auto-fills HTTP_REFERER from its own
        // request history when it isn't set explicitly (see AbstractBrowser::doRequest) — so
        // leaving it unset here would silently backfill the *previous, same-origin* GET's URL as
        // Referer and validate the request regardless of the (correctly rejected) Origin, making
        // this test pass for the wrong reason. A genuine cross-origin attacker's browser sends
        // Referer pointing at the attacker's own page, never at this app, so both headers must be
        // forged here to model that.
        $client->request(
            'POST',
            '/admin/2fa-setup/confirm',
            parameters: ['code' => $code, '_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/attack'],
        );
        self::assertResponseStatusCodeSame(422);

        // Still not enrolled/authenticated — the (valid) code was never even evaluated.
        $client->request('GET', '/admin');
        self::assertNotSame(200, $client->getResponse()->getStatusCode());
    }
}
