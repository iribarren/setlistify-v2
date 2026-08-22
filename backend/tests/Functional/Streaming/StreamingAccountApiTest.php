<?php

declare(strict_types=1);

namespace App\Tests\Functional\Streaming;

use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Functional\Auth\AuthWebTestCase;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * US-2, US-3, D-77, AC-7.1: ownership isolation (mirrors `App\Tests\Functional\Concert\
 * ConcertOwnershipTest`'s shape exactly) and the serialization allowlist.
 */
final class StreamingAccountApiTest extends AuthWebTestCase
{
    public function testListReturnsOnlyTheCurrentUsersAccounts(): void
    {
        $client = $this->createAuthClient();
        $owner = $this->registerAndLogin($client);
        $this->persistAccountFor($client, $owner['email']);

        $intruder = $this->registerAndLogin($client);
        $this->persistAccountFor($client, $intruder['email']);

        $client->request('GET', '/api/streaming/accounts', server: self::authHeaders($owner['accessToken']));
        self::assertResponseIsSuccessful();
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $members = self::asList($data['member']);
        self::assertCount(1, $members, 'AC-2.4: filtered to the current owner before anything else runs.');
    }

    public function testDeletingSomeoneElsesAccountReturns404IdenticalToMissingId(): void
    {
        // debug:false — the 404 bodies must match byte-for-byte, same pattern as ConcertOwnershipTest.
        $client = $this->createAuthClient(['debug' => false]);
        $owner = $this->registerAndLogin($client);
        $account = $this->persistAccountFor($client, $owner['email']);

        $intruder = $this->registerAndLogin($client);

        $client->request('DELETE', \sprintf('/api/streaming/accounts/%d', $account->getId() ?? 0), server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crossOwnerBody = (string) $client->getResponse()->getContent();

        $client->request('DELETE', '/api/streaming/accounts/999999999', server: self::authHeaders($intruder['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $missingBody = (string) $client->getResponse()->getContent();

        self::assertSame($missingBody, $crossOwnerBody);
    }

    public function testOwnerCanUnlinkTheirOwnAccountAndItStopsAppearingInTheList(): void
    {
        $client = $this->createAuthClient();
        $owner = $this->registerAndLogin($client);
        $account = $this->persistAccountFor($client, $owner['email']);

        $client->request('DELETE', \sprintf('/api/streaming/accounts/%d', $account->getId() ?? 0), server: self::authHeaders($owner['accessToken']));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $client->request('GET', '/api/streaming/accounts', server: self::authHeaders($owner['accessToken']));
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        self::assertSame(0, $data['totalItems'], 'AC-3.4: no longer present after unlinking.');
    }

    public function testAnonymousRequestsAreRejected(): void
    {
        $client = $this->createAuthClient();

        $client->request('GET', '/api/streaming/accounts');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * AC-7.1: an explicit allowlist. A new field on `StreamingAccountOutput` that isn't added here
     * too makes this test fail — the point of an allowlist test, not an incidental side effect.
     */
    public function testListResponseContainsOnlyTheAllowlistedFieldsNeverAToken(): void
    {
        $client = $this->createAuthClient();
        $owner = $this->registerAndLogin($client);
        $this->persistAccountFor($client, $owner['email']);

        $client->request('GET', '/api/streaming/accounts', server: self::authHeaders($owner['accessToken']));
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $member = self::asArray(self::asList($data['member'])[0]);

        $allowedKeys = ['@id', '@type', 'id', 'provider', 'providerDisplayName', 'providerAccountId', 'scopes', 'linkedAt', 'status'];

        foreach (array_keys($member) as $key) {
            self::assertContains($key, $allowedKeys, \sprintf('Unexpected field "%s" in the streaming account payload — AC-7.1 requires an explicit allowlist.', $key));
        }

        foreach (['accessToken', 'refreshToken', 'expiresAt', 'token', 'code', 'codeVerifier'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $member, \sprintf('"%s" must never appear in the response (AC-2.3/AC-7.1).', $forbidden));
        }
    }

    /** @return array{email: string, password: string, accessToken: string} */
    private function registerAndLogin(KernelBrowser $client): array
    {
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);

        return ['email' => $credentials['email'], 'password' => $credentials['password'], 'accessToken' => $login['accessToken']];
    }

    /** @return array<string, string> */
    private static function authHeaders(string $accessToken): array
    {
        return [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
        ];
    }

    private function persistAccountFor(KernelBrowser $client, string $email): StreamingAccount
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        $account = new StreamingAccount(
            user: $user,
            provider: TestDoubleStreamingProvider::KEY,
            accessToken: 'stored-access',
            refreshToken: 'stored-refresh',
            expiresAt: new \DateTimeImmutable('+1 hour'),
            scopes: ['double-scope'],
            providerAccountId: 'ext-'.bin2hex(random_bytes(4)),
            providerDisplayName: 'Ext Display',
            now: new \DateTimeImmutable(),
        );

        $em->persist($account);
        $em->flush();

        return $account;
    }

    /** @return array<string, mixed> */
    private static function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /** @return list<mixed> */
    private static function asList(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }
}
