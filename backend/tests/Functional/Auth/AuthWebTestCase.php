<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\JsonResponseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared scaffolding for auth functional tests (docs/specs/2026-08-21-auth-and-accounts.md).
 *
 * Every test clears the Redis-backed rate-limiter pool first: US-9's limits are real and shared
 * across the whole `test` environment's Redis namespace, so without this, running the suite twice
 * in a row (or running these tests alongside each other) would start tripping 429s that have
 * nothing to do with what a given test is actually asserting. Tests that specifically exercise a
 * rate limit (`RateLimitingTest`) rely on this same clean-slate guarantee to make the limit
 * predictable.
 */
abstract class AuthWebTestCase extends WebTestCase
{
    use JsonResponseTrait;

    /**
     * Boots a fresh client and clears the rate-limiter pool. `getContainer()` itself boots the
     * kernel, so this — not `parent::setUp()` — is the only place that may touch the container;
     * calling `getContainer()` before `createClient()` makes `createClient()` throw ("the kernel
     * should only be booted once"). Every test in this suite must call this instead of
     * `static::createClient()` directly.
     */
    /** @param array<string, mixed> $options */
    protected function createAuthClient(array $options = []): KernelBrowser
    {
        $client = static::createClient($options);

        static::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    /** A fresh, syntactically valid, never-before-used email for one test. */
    protected static function uniqueEmail(string $label = 'user'): string
    {
        return \sprintf('%s.%s@example.test', $label, bin2hex(random_bytes(6)));
    }

    protected static function strongPassword(): string
    {
        // Long, random, never appears in a breach corpus — irrelevant here since
        // config/packages/validator.yaml disables the compromised-password check in `test` (D-2).
        return 'Xk4-'.bin2hex(random_bytes(10)).'-Zq9';
    }

    /**
     * Registers a user via the real endpoint and returns its email/password so the caller can log
     * in with them — exercising the same path a real client would rather than inserting rows
     * directly.
     *
     * @return array{email: string, password: string}
     */
    protected function registerUser(KernelBrowser $client, ?string $email = null): array
    {
        $email ??= self::uniqueEmail();
        $password = self::strongPassword();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());

        return ['email' => $email, 'password' => $password];
    }

    /**
     * Strips JSON-LD's per-response random blank-node `@id` (`/api/.well-known/genid/...`) before
     * comparing two response bodies for equality — real, but irrelevant noise for tests asserting
     * two *different* requests produced the same observable outcome (AC-2.4, AC-6.1).
     */
    protected static function withoutVolatileFields(string $json): string
    {
        $data = self::decodeJsonObject($json);
        unset($data['@id']);

        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    /**
     * The mailer's message-logger records two events per email sent through Messenger (which this
     * app's Mailer service uses transparently once symfony/messenger is installed): one `queued`
     * pre-dispatch event and one real "sent" event once the transport actually runs — see
     * `Symfony\Component\Mailer\Mailer::send()`. `MailerAssertionsTrait::assertEmailCount()`
     * already accounts for this; anything in this test suite that counts or indexes messages by
     * hand must use this helper instead of `getMailerMessages()` directly, or it will silently
     * double-count.
     *
     * @return list<\Symfony\Component\Mime\RawMessage>
     */
    protected static function sentMailerMessages(): array
    {
        $messages = [];
        foreach (static::getMailerEvents() as $event) {
            if (!$event->isQueued()) {
                $messages[] = $event->getMessage();
            }
        }

        return $messages;
    }

    /** @return array{accessToken: string, refreshTokenCookie: string} */
    protected function loginUser(KernelBrowser $client, string $email, string $password): array
    {
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $cookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie, 'login must set the refresh-token cookie');

        $accessToken = $data['accessToken'];
        self::assertIsString($accessToken);
        $cookieValue = $cookie->getValue();
        self::assertIsString($cookieValue);

        return [
            'accessToken' => $accessToken,
            'refreshTokenCookie' => $cookieValue,
        ];
    }
}
