<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * US-2, AC-2.4/AC-9.4: wrong password, unknown email and a disabled/unverified account all fail
 * identically.
 */
final class LoginTest extends AuthWebTestCase
{
    public function testLoginSuccessReturnsAccessTokenAndSetsRefreshCookie(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode($registered, \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertArrayHasKey('accessToken', $data);
        self::assertSame('Bearer', $data['tokenType']);
        self::assertGreaterThan(0, $data['expiresIn']);
        self::assertArrayNotHasKey('refreshToken', $data, 'web (default) transport never puts the refresh token in the body');

        $cookies = $client->getResponse()->headers->getCookies();
        self::assertNotEmpty($cookies);
        self::assertSame('refresh_token', $cookies[0]->getName());
        self::assertTrue($cookies[0]->isHttpOnly());
    }

    public function testAccessTokenPayloadCarriesNoEmail(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        [, $payload] = explode('.', $login['accessToken']);
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        self::assertIsString($decoded);
        $claims = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        self::assertArrayHasKey('sub', $claims);
        self::assertArrayHasKey('roles', $claims);
        self::assertArrayHasKey('jti', $claims);
        self::assertArrayNotHasKey('username', $claims);
        self::assertArrayNotHasKey('email', $claims);

        $flat = json_encode($claims, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($registered['email'], $flat);
    }

    public function testWrongPasswordReturnsGeneric401(): void
    {
        // debug: false — otherwise RFC7807's debug-only "trace" entry differs between the two
        // requests just because they were thrown from different lines of *this test*, which would
        // make the comparison below noise, not signal (AC-2.4 is about the production shape).
        $client = $this->createAuthClient(['debug' => false]);
        $registered = $this->registerUser($client);

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email'], 'password' => 'this is not the right password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $wrongPasswordBody = (string) $client->getResponse()->getContent();

        // Same client, second request — WebTestCase forbids booting a second kernel mid-test, but
        // a KernelBrowser can issue any number of requests against the one it already booted.
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => self::uniqueEmail('nobody'), 'password' => 'this is not the right password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $unknownEmailBody = (string) $client->getResponse()->getContent();

        self::assertSame(
            self::withoutVolatileFields($wrongPasswordBody),
            self::withoutVolatileFields($unknownEmailBody),
            'AC-2.4: wrong password and unknown email must be indistinguishable',
        );
    }
}
