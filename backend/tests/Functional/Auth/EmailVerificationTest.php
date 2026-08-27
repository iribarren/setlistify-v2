<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpFoundation\Response;

/** US-7, AC-7.1–AC-7.6. */
final class EmailVerificationTest extends AuthWebTestCase
{
    use MailerAssertionsTrait;

    public function testRegistrationSendsAVerificationEmail(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        self::assertEmailCount(1);
        $email = self::sentMailerMessages()[0] ?? null;
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', $registered['email']);
    }

    public function testConfirmWithValidTokenVerifiesTheAccount(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $token = $this->extractPlaintextVerificationToken();

        $client->request(
            'POST',
            '/api/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $token], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $login = $this->loginUser($client, $registered['email'], $registered['password']);
        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken'], 'HTTP_ACCEPT' => 'application/ld+json']);
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertTrue($data['emailVerified']);
    }

    public function testConfirmWithUnknownTokenReturnsGeneric400(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            '/api/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => 'not-a-real-token'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testConfirmWithAnAlreadyUsedTokenReturnsTheSameGeneric400(): void
    {
        $client = $this->createAuthClient();
        $this->registerUser($client);
        $token = $this->extractPlaintextVerificationToken();

        $client->request(
            'POST',
            '/api/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $token], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request(
            'POST',
            '/api/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $token], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testResendRequiresAuthentication(): void
    {
        $client = $this->createAuthClient();

        $client->request('POST', '/api/email-verification/resend', server: ['CONTENT_TYPE' => 'application/ld+json'], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * D-252 (docs/specs/2026-08-27-admin-set-email-verified.md): a token consumed for a user who
     * was already verified — e.g. by the admin's manual-verify action — must not overwrite the
     * existing timestamp, must still consume the token, and the endpoint's response stays the same
     * success/204 either way, invisible to the client.
     */
    public function testConfirmWithValidTokenForAlreadyVerifiedUserDoesNotOverwriteTimestamp(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $token = $this->extractPlaintextVerificationToken();

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => $registered['email']]);
        self::assertNotNull($user);
        $adminSetAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $user->markEmailVerified($adminSetAt);
        $em->flush();
        $userId = $user->getId();

        $client->request(
            'POST',
            '/api/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $token], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(\App\Entity\User::class)->find($userId);
        self::assertNotNull($user);
        self::assertEquals($adminSetAt, $user->getEmailVerifiedAt());

        // The token is still consumed — a second attempt with the same token is rejected.
        $client->request(
            'POST',
            '/api/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $token], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testResendAlwaysReturns202RegardlessOfVerificationState(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->request('POST', '/api/email-verification/resend', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken'],
            'CONTENT_TYPE' => 'application/ld+json',
        ], content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        // Symfony's mailer message logger is a "kernel.reset" service — it's cleared between each
        // request the test client makes, so this reflects only the resend call above, not the
        // registration email that preceded it.
        self::assertEmailCount(1, message: 'the resend');
    }

    /** Must be called right after the request whose email it reads — see the reset note above. */
    private function extractPlaintextVerificationToken(): string
    {
        $message = self::sentMailerMessages()[0] ?? null;
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        $body = $message->getHtmlBody();
        self::assertIsString($body);

        self::assertMatchesRegularExpression('#verify-email\?token=([a-f0-9]+)#', $body);
        $matches = [];
        preg_match('#verify-email\?token=([a-f0-9]+)#', $body, $matches);
        $token = $matches[1] ?? null;
        self::assertIsString($token);

        return $token;
    }
}
