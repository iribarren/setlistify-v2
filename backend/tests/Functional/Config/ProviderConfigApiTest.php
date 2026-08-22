<?php

declare(strict_types=1);

namespace App\Tests\Functional\Config;

use App\Entity\User;
use App\Service\Provider\PlaybackMode;
use App\Service\Provider\ProviderRegistry;
use App\Service\Provider\ProviderSettingWriter;
use App\Tests\Functional\Auth\AuthWebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/config/providers` (docs/specs/2026-08-22-backoffice-provider-configuration.md, US-6).
 *
 * Every response in this suite is `application/ld+json` (this codebase's one API format,
 * `config/packages/api_platform.yaml`) — a collection comes back as `{"member": [...]}`, and each
 * item carries a JSON-LD id and type key alongside the declared properties (not written literally
 * here — see the constant below — since php-cs-fixer's `phpdoc_no_alias_tag` rewrites a literal
 * "at-type" token anywhere in a docblock into "at-var"), same shape
 * `App\Tests\Functional\Streaming\StreamingAccountApiTest`'s own allowlist test asserts against.
 */
final class ProviderConfigApiTest extends AuthWebTestCase
{
    /** AC-6.1: unauthenticated, 200. */
    public function testIsPublicAndReturnsOk(): void
    {
        $client = $this->createAuthClient();
        static::getContainer()->get('provider.redis')->del(ProviderRegistry::CACHE_KEY);

        $client->request('GET', '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK, (string) $client->getResponse()->getContent());
    }

    /**
     * AC-6.2/AC-6.4/AC-9.2: exactly the JSON-LD id/type envelope keys plus `key`, `displayName`,
     * `enabled`, `playbackMode`, `isDefault` — a strict allowlist against a hardcoded literal list,
     * and no credential-shaped key.
     */
    public function testEveryItemHasExactlyTheDeclaredFieldsAndNoCredentialShapedKey(): void
    {
        $client = $this->createAuthClient();
        static::getContainer()->get('provider.redis')->del(ProviderRegistry::CACHE_KEY);

        $client->request('GET', '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        $members = $this->members($client);
        self::assertNotEmpty($members, 'spotify is seeded and its adapter is registered — the list must not be empty.');

        $expectedKeys = ['@type', '@id', 'key', 'displayName', 'enabled', 'playbackMode', 'isDefault'];
        foreach ($members as $item) {
            self::assertSame($expectedKeys, array_keys($item), 'AC-6.4: exact key set, nothing added/removed/renamed.');

            foreach (array_keys($item) as $key) {
                if ('key' === $key || '@type' === $key || '@id' === $key) {
                    // AC-9.2's regex targets a credential-shaped SUFFIX ("apiKey", "clientKey", …).
                    // `key` alone is the resource's own provider identifier (spotify/youtube), not
                    // a credential, and `@type`/`@id` are JSON-LD envelope fields, not payload data.
                    continue;
                }
                self::assertDoesNotMatchRegularExpression('/secret|token|client_?id|credential|.+key$|password/i', $key, "AC-9.2: '{$key}' looks credential-shaped.");
            }
        }
    }

    /** D-103/AC-6.8: notes never leaves the entity. */
    public function testNotesNeverAppearsInTheResponse(): void
    {
        $client = $this->createAuthClient();
        static::getContainer()->get(ProviderSettingWriter::class)->update(
            'spotify',
            enabled: true,
            playbackMode: PlaybackMode::Embed,
            isDefault: true,
            notes: 'incident-name-must-not-leak-2026-08-22',
            actor: $this->adminActor(),
        );

        $client->request('GET', '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('incident-name-must-not-leak', (string) $client->getResponse()->getContent());
    }

    /** AC-4.7/D-99: a disabled provider still appears, with enabled: false — it does not vanish. */
    public function testADisabledProviderStillAppearsWithEnabledFalse(): void
    {
        $client = $this->createAuthClient();
        static::getContainer()->get(ProviderSettingWriter::class)->update(
            'spotify',
            enabled: false,
            playbackMode: PlaybackMode::Embed,
            isDefault: false,
            notes: null,
            actor: $this->adminActor(),
        );

        $client->request('GET', '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        $members = $this->members($client);
        $spotify = array_values(array_filter($members, static fn (array $item) => ($item['key'] ?? null) === 'spotify'));
        self::assertNotEmpty($spotify, 'AC-4.7: a disabled provider must still appear.');
        self::assertFalse($spotify[0]['enabled']);

        // Restore for other tests.
        static::getContainer()->get(ProviderSettingWriter::class)->update('spotify', enabled: true, playbackMode: PlaybackMode::Embed, isDefault: true, notes: null, actor: $this->adminActor());
    }

    /** D-99: a settings row with no registered adapter (youtube, this branch) never appears. */
    public function testAProviderWithNoAdapterDoesNotAppear(): void
    {
        $client = $this->createAuthClient();
        static::getContainer()->get('provider.redis')->del(ProviderRegistry::CACHE_KEY);

        $client->request('GET', '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        $members = $this->members($client);
        $keys = array_map(static fn (array $item) => $item['key'] ?? null, $members);
        self::assertNotContains('youtube', $keys, 'D-99: youtube has no registered adapter in this branch — it must not appear.');
    }

    /** D-98/AC-6.5: Cache-Control: no-store. */
    public function testResponseCarriesCacheControlNoStore(): void
    {
        $client = $this->createAuthClient();

        $client->request('GET', '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    /** AC-6.6: read-only — no POST/PATCH/PUT/DELETE exposed. */
    public function testOnlyGetIsAllowed(): void
    {
        $client = $this->createAuthClient();

        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $client->request($method, '/api/config/providers', server: ['HTTP_ACCEPT' => 'application/ld+json']);
            self::assertContains(
                $client->getResponse()->getStatusCode(),
                [Response::HTTP_METHOD_NOT_ALLOWED, Response::HTTP_NOT_FOUND],
                "{$method} /api/config/providers must not be a valid write operation.",
            );
        }
    }

    /** AC-6.7: part of the public OpenAPI contract. */
    public function testAppearsInTheOpenApiSpec(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();

        $spec = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $paths = $spec['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertArrayHasKey('/api/config/providers', $paths);
    }

    /** @return list<array<string, mixed>> */
    private function members(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $member = $data['member'] ?? null;
        self::assertIsArray($member);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($member);

        return $list;
    }

    private function adminActor(): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = new User(\sprintf('provider-config-api.%s@example.test', Uuid::v4()), 'placeholder-hash');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
