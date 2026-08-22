<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared scaffolding for the backoffice's functional test suite
 * (docs/specs/2026-08-21-backoffice-foundation.md).
 *
 * The admin firewall's `enable_csrf: true` uses Symfony's stateless, same-origin CSRF scheme
 * (`SameOriginCsrfTokenManager`), not a per-request random token: `csrf_token('authenticate')`
 * always renders the literal string `"csrf-token"` (the cookie name doubling as a same-origin
 * placeholder value, see `config/packages/csrf.yaml`), and validity is decided by the request's
 * `Origin`/`Referer` header matching the app's own host — never by comparing against a stored
 * value. So every state-changing admin request in this suite must carry `HTTP_ORIGIN` matching
 * the test client's own host, and the literal `_csrf_token=csrf-token` field.
 */
abstract class AdminWebTestCase extends WebTestCase
{
    protected const string ORIGIN = 'http://localhost';
    protected const string CSRF_TOKEN = 'csrf-token';

    /**
     * Boots a fresh client and clears the rate-limiter pool — the admin login/2FA/reveal-email
     * limiters (config/packages/rate_limiter.yaml) are real and shared, same reasoning as
     * `App\Tests\Functional\Auth\AuthWebTestCase::createAuthClient()`.
     */
    protected function createAdminClient(): KernelBrowser
    {
        $client = static::createClient();
        static::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    /** @return array{email: string, password: string, user: User} */
    protected function createAdmin(): array
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = self::uniqueEmail('admin');
        $password = self::strongPassword();

        $user = new User($email, 'placeholder');
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $em->persist($user);
        $em->flush();

        return ['email' => $email, 'password' => $password, 'user' => $user];
    }

    protected static function uniqueEmail(string $label = 'user'): string
    {
        return \sprintf('%s.%s@example.test', $label, bin2hex(random_bytes(6)));
    }

    protected static function strongPassword(): string
    {
        return 'Xk4-'.bin2hex(random_bytes(10)).'-Zq9';
    }

    /**
     * Submits the admin login form. Does NOT follow redirects — the caller decides whether to
     * expect the 2FA form (unenrolled/enrolled) or a denial.
     */
    protected function postAdminLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request(
            'GET',
            '/admin/login',
        );

        $client->request(
            'POST',
            '/admin/login_check',
            parameters: [
                '_username' => $email,
                '_password' => $password,
                '_csrf_token' => self::CSRF_TOKEN,
            ],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
    }

    /**
     * Full login → forced enrollment → confirm flow, ending fully authenticated. Returns the
     * client positioned right after the confirm redirect (not yet followed).
     */
    protected function loginAndEnroll(KernelBrowser $client, string $email, string $password): void
    {
        $this->postAdminLogin($client, $email, $password);
        self::assertResponseRedirects();

        $client->request('GET', '/admin/2fa-setup');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        \preg_match('/<p class="secret">([A-Z2-7]+)<\/p>/', $html, $matches);
        self::assertArrayHasKey(1, $matches, 'enrollment page must render the TOTP secret');
        $secret = $matches[1] ?? throw new \LogicException('unreachable — asserted above');

        $totp = TOTP::createFromSecret($secret);
        $code = $totp->now();

        $client->request(
            'POST',
            '/admin/2fa-setup/confirm',
            parameters: ['code' => $code, '_csrf_token' => self::CSRF_TOKEN],
            server: ['HTTP_ORIGIN' => self::ORIGIN],
        );
        self::assertResponseRedirects('/admin');
    }

    /** Registers and logs in a plain ROLE_USER via the real API, returning its JWT access token. */
    protected function apiAccessToken(KernelBrowser $client): string
    {
        return $this->apiRegisterAndLogin($client)['accessToken'];
    }

    /**
     * Registers and logs in a plain ROLE_USER via the real API — the common setup for tests that
     * need one ordinary account to act as an admin action's *subject* or to prove API/admin
     * isolation.
     *
     * @return array{email: string, password: string, accessToken: string}
     */
    protected function apiRegisterAndLogin(KernelBrowser $client, ?string $email = null): array
    {
        $email ??= self::uniqueEmail('apiuser');
        $password = self::strongPassword();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $accessToken = $data['accessToken'] ?? null;
        self::assertIsString($accessToken);

        return ['email' => $email, 'password' => $password, 'accessToken' => $accessToken];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function apiCreateConcert(KernelBrowser $client, string $accessToken, array $payload): array
    {
        $client->request(
            'POST',
            '/api/concerts',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
