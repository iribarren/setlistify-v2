<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-4: rotation, families and reuse detection (AC-4.1–AC-4.4), plus the grace-window mitigation
 * (R-3) that keeps a dropped-response retry from looking like theft.
 */
final class RefreshTokenTest extends AuthWebTestCase
{
    public function testRefreshRotatesTheTokenAndTheOldOneNeverWorksAgain(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');

        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertArrayHasKey('accessToken', $data);

        $newCookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($newCookie);
        self::assertNotSame($login['refreshTokenCookie'], $newCookie->getValue(), 'a new refresh token must be issued on rotation');

        // Backdate the OLD token's rotation past the grace window, then replay it: it must now be
        // treated as theft (AC-4.4), not the grace-window's benign-duplicate path.
        $this->backdateRotation($login['refreshTokenCookie'], secondsAgo: 60);

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'AC-4.4: replaying a stale rotated token outside the grace window is theft');
    }

    public function testReuseOutsideGraceWindowRevokesTheWholeFamily(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertResponseIsSuccessful();
        $rotated = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $newCookie = $client->getResponse()->headers->getCookies()[0];

        $this->backdateRotation($login['refreshTokenCookie'], secondsAgo: 60);

        // Replay the original (now stale) token — kills the family, including the token that was
        // legitimately issued by the rotation above.
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // The legitimately-rotated token is now also dead, because the whole family was revoked.
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $newCookie->getValue(), null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'AC-4.4: reuse kills every token in the family, not just the replayed one');
    }

    /**
     * R-3: a duplicate refresh presented within the grace window (a retried request after a
     * dropped response, or two tabs racing) must NOT be treated as theft — it gets a fresh, usable
     * pair instead of a 401.
     */
    public function testReuseWithinGraceWindowIsTreatedAsBenignDuplicate(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertResponseIsSuccessful();

        // Immediately replay the same (just-rotated) token — well inside the grace window.
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');

        self::assertResponseIsSuccessful('R-3: a near-immediate duplicate refresh must not log the user out');
    }

    private function backdateRotation(string $plaintextRefreshToken, int $secondsAgo): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hash = hash('sha256', $plaintextRefreshToken);
        $token = $em->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $hash]);
        self::assertInstanceOf(RefreshToken::class, $token);

        $reflection = new \ReflectionProperty(RefreshToken::class, 'rotatedAt');
        $reflection->setValue($token, (new \DateTimeImmutable())->sub(new \DateInterval(\sprintf('PT%dS', $secondsAgo))));

        $em->flush();
        $em->clear();
    }
}
