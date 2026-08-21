<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** US-5, AC-5.1–AC-5.4. */
final class LogoutTest extends AuthWebTestCase
{
    public function testLogoutRevokesTheRefreshTokenAndClearsTheCookie(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->getCookieJar()->set(new Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/logout', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $clearedCookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($clearedCookie);
        self::assertTrue($clearedCookie->isCleared() || '' === $clearedCookie->getValue(), 'AC-5.2: the cookie is cleared on logout');

        // AC-5.3: the revoked token now fails at /api/token/refresh.
        $client->getCookieJar()->set(new Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLogoutIsIdempotentEvenWithNoTokenPresented(): void
    {
        $client = $this->createAuthClient();

        $client->request('POST', '/api/logout', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'AC-5.4: logging out never fails visibly, even with nothing to revoke');
    }

    public function testLogoutIsIdempotentWithAnAlreadyInvalidToken(): void
    {
        $client = $this->createAuthClient();

        $client->getCookieJar()->set(new Cookie('refresh_token', 'not-a-real-token', null, '/api'));
        $client->request('POST', '/api/logout', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }
}
