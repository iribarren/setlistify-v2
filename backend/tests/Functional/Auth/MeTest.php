<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\HttpFoundation\Response;

/** US-8, AC-8.1/AC-8.2. */
final class MeTest extends AuthWebTestCase
{
    public function testMeRequiresAuthentication(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeReturnsOwnIdentityAndNothingSensitive(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken'],
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);

        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame($registered['email'], $data['email']);
        self::assertFalse($data['emailVerified']);
        self::assertSame(['ROLE_USER'], $data['roles']);
        self::assertArrayNotHasKey('password', $data);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('$2y$', $body);
    }

    public function testMeRejectsAGarbageBearerToken(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer not-a-real-jwt',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
