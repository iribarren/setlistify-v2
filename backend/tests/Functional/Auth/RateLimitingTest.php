<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * US-9, AC-9.1–AC-9.3. Each limiter here is configured in `config/packages/rate_limiter.yaml`;
 * these tests trip the *credentials*-scoped limiters (tighter than the IP-scoped ones, so they're
 * cheaper to reach deterministically in a test).
 */
final class RateLimitingTest extends AuthWebTestCase
{
    public function testLoginIsRateLimitedPerCredentials(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        // login_credentials: 5 per 15 min (config/packages/rate_limiter.yaml).
        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/login',
                server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
                content: json_encode(['email' => $registered['email'], 'password' => 'wrong-password-'.$i], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email'], 'password' => $registered['password']], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'), 'AC-9.2: Retry-After header present');
        self::assertStringContainsString('problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testRegistrationIsRateLimitedPerIp(): void
    {
        $client = $this->createAuthClient();

        // registration_ip: 5 per hour.
        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/users',
                server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
                content: json_encode(['email' => self::uniqueEmail(), 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => self::uniqueEmail(), 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testPasswordResetRequestIsRateLimitedPerEmail(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        // password_reset_request_email: 3 per hour.
        for ($i = 0; $i < 3; ++$i) {
            $client->request(
                'POST',
                '/api/password-reset/request',
                server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
                content: json_encode(['email' => $registered['email']], \JSON_THROW_ON_ERROR),
            );
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        }

        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email']], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** AC-9.6: a broken rate-limiter storage fails the request closed, not open. */
    public function testRateLimiterFailsClosedWhenStorageIsUnavailable(): void
    {
        $brokenPool = new class implements \Psr\Cache\CacheItemPoolInterface {
            public function getItem(string $key): \Psr\Cache\CacheItemInterface
            {
                throw new class extends \Exception implements \Psr\Cache\CacheException {};
            }

            /** @return iterable<string, \Psr\Cache\CacheItemInterface> */
            public function getItems(array $keys = []): iterable
            {
                throw new class extends \Exception implements \Psr\Cache\CacheException {};
            }

            public function hasItem(string $key): bool
            {
                throw new class extends \Exception implements \Psr\Cache\CacheException {};
            }

            public function clear(): bool
            {
                return true;
            }

            public function deleteItem(string $key): bool
            {
                return true;
            }

            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }

            /** The real cache pool implements ResetInterface; the kernel-shutdown resetter calls
             *  this unconditionally on whatever currently occupies this service id. */
            public function reset(): void
            {
            }
        };

        // Deliberately NOT createAuthClient(): that helper's own cache.rate_limiter->clear() call
        // would initialize the real service, and a service already initialized cannot be replaced
        // with set() afterwards. This test wants the broken pool in place from the first use.
        $client = static::createClient();
        static::getContainer()->set('cache.rate_limiter', $brokenPool);

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => self::uniqueEmail(), 'password' => 'whatever-password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'AC-9.6: broken limiter storage must reject, never silently allow');
    }
}
