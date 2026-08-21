<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-1, and US-10's bolded criterion (AC-10.3): no shape of the registration request can produce
 * anything other than exactly `["ROLE_USER"]`.
 */
final class RegistrationTest extends AuthWebTestCase
{
    public function testRegisterReturnsExactlyTheDocumentedFields(): void
    {
        $client = $this->createAuthClient();
        $email = self::uniqueEmail();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $email, 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame($email, $data['email']);
        self::assertFalse($data['emailVerified']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('roles', $data, 'AC-10.2: roles is not a writable/readable field on this DTO at all');
    }

    public function testDuplicateEmailReturns422WithGenericMessage(): void
    {
        $client = $this->createAuthClient();
        $email = self::uniqueEmail();
        $this->registerUser($client, $email);

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => $email, 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('cannot be used', $body);
    }

    public function testWeakPasswordReturns422(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => self::uniqueEmail(), 'password' => 'short'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * AC-10.3, core case: a top-level `roles` property in the registration payload is simply
     * ignored — {@see \App\ApiResource\RegisterUserInput} has no such property to bind it to.
     */
    public function testRolesPropertyInRequestBodyCannotGrantAdmin(): void
    {
        $client = $this->createAuthClient();
        $email = self::uniqueEmail();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode([
                'email' => $email,
                'password' => self::strongPassword(),
                'roles' => ['ROLE_ADMIN'],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());

        $this->assertUserHasExactlyRoleUser($email);
    }

    /** AC-10.3, nested case: a nested `roles` shape is equally inert. */
    public function testNestedRolesShapeCannotGrantAdmin(): void
    {
        $client = $this->createAuthClient();
        $email = self::uniqueEmail();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode([
                'email' => $email,
                'password' => self::strongPassword(),
                'user' => ['roles' => ['ROLE_ADMIN']],
                'attributes' => ['roles' => ['ROLE_ADMIN']],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());

        $this->assertUserHasExactlyRoleUser($email);
    }

    /** AC-11.1: the registration response never carries a password hash. */
    public function testPasswordHashNeverAppearsInAnyRegistrationResponse(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['email' => self::uniqueEmail(), 'password' => self::strongPassword()], \JSON_THROW_ON_ERROR),
        );

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('$2y$', $body, 'no bcrypt hash prefix in the response');
        self::assertStringNotContainsString('password', $body);
    }

    private function assertUserHasExactlyRoleUser(string $email): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }
}
