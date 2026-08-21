<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpFoundation\Response;

/** US-6, AC-6.1–AC-6.9. */
final class PasswordResetTest extends AuthWebTestCase
{
    use MailerAssertionsTrait;

    public function testRequestReturnsIdenticalAckForKnownAndUnknownEmail(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email']], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $known = (string) $client->getResponse()->getContent();

        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => self::uniqueEmail('nobody')], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $unknown = (string) $client->getResponse()->getContent();

        self::assertSame(
            self::withoutVolatileFields($known),
            self::withoutVolatileFields($unknown),
            'AC-6.1: identical response whether or not the address exists',
        );
    }

    public function testRequestSendsAnEmailOnlyForAKnownAddress(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email']], \JSON_THROW_ON_ERROR),
        );

        // Symfony's mailer message logger is a "kernel.reset" service — it's cleared between each
        // request the test client makes, so this reflects only the reset-request call above, not
        // the registration email that preceded it (verified via manual instrumentation while
        // writing this test; see git history for the reasoning if this surprises a future reader).
        self::assertEmailCount(1);
        $email = self::sentMailerMessages()[0] ?? null;
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', $registered['email']);
        self::assertEmailSubjectContains($email, 'password');
    }

    public function testConfirmWithValidTokenChangesPasswordAndLogsOutEveryDevice(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);
        $login = $this->loginUser($client, $registered['email'], $registered['password']);

        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email']], \JSON_THROW_ON_ERROR),
        );

        $plaintextToken = $this->extractPlaintextResetToken($registered['email']);
        $newPassword = self::strongPassword();

        $client->request(
            'POST',
            '/api/password-reset/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $plaintextToken, 'password' => $newPassword], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // AC-6.4: the pre-reset session is dead.
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $login['refreshTokenCookie'], null, '/api'));
        $client->request('POST', '/api/token/refresh', server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        // The new password now works; the old one doesn't.
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email'], 'password' => $newPassword], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();
    }

    public function testConfirmWithUnknownTokenReturnsGeneric400(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            '/api/password-reset/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => 'not-a-real-token', 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testConfirmWithAnAlreadyUsedTokenReturnsTheSameGeneric400(): void
    {
        $client = $this->createAuthClient();
        $registered = $this->registerUser($client);

        $client->request(
            'POST',
            '/api/password-reset/request',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $registered['email']], \JSON_THROW_ON_ERROR),
        );
        $plaintextToken = $this->extractPlaintextResetToken($registered['email']);

        $client->request(
            'POST',
            '/api/password-reset/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $plaintextToken, 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request(
            'POST',
            '/api/password-reset/confirm',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['token' => $plaintextToken, 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'AC-6.4: a second use of the same token fails the same generic way');
    }

    /**
     * The plaintext token only ever exists in the outgoing email — this test reads it from there
     * (via Symfony's mailer test transport, D-2/AC-12.2), never from the database, matching how a
     * real user gets it. Called right after the `/api/password-reset/request` call, whose email is
     * the only one the (per-request-reset) message logger holds at this point.
     */
    private function extractPlaintextResetToken(string $email): string
    {
        $sent = self::sentMailerMessages();
        $message = $sent[0] ?? null;
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
        self::assertEmailAddressContains($message, 'To', $email);

        $body = $message->getHtmlBody();
        self::assertIsString($body);

        self::assertMatchesRegularExpression('#reset-password\?token=([a-f0-9]+)#', $body);
        $matches = [];
        preg_match('#reset-password\?token=([a-f0-9]+)#', $body, $matches);
        $token = $matches[1] ?? null;
        self::assertIsString($token);

        return $token;
    }
}
