<?php

declare(strict_types=1);

namespace App\Tests\Functional\Streaming;

use App\Tests\Functional\Auth\AuthWebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC-1.1: starting the flow returns an authorization URL produced by the adapter. Uses the
 * `spotify` provider key — the only one with a redirect URI configured in
 * `App\Service\Streaming\Link\LinkFlowService`'s real, container-wired binding
 * (`config/services.yaml`) — `authorizationUrl()` itself makes no network call, so this is still
 * offline (D-2). `App\Tests\Functional\Streaming\LinkFlowServiceTest` covers the full round trip
 * (including the network-touching `exchangeCode()` step) against the test-double adapter instead.
 */
final class StreamingLinkStartApiTest extends AuthWebTestCase
{
    public function testStartReturnsAnAuthorizationUrl(): void
    {
        $client = $this->createAuthClient();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);

        $client->request(
            'POST',
            '/api/streaming/link',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken']],
            content: json_encode(['provider' => 'spotify'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $client->getResponse()->getContent());
        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertIsString($data['authorizationUrl']);
        self::assertStringContainsString('/authorize?', $data['authorizationUrl']);
        self::assertStringContainsString('code_challenge=', $data['authorizationUrl']);
    }

    public function testUnknownProviderIsA404(): void
    {
        $client = $this->createAuthClient();
        $credentials = $this->registerUser($client);
        $login = $this->loginUser($client, $credentials['email'], $credentials['password']);

        $client->request(
            'POST',
            '/api/streaming/link',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$login['accessToken']],
            content: json_encode(['provider' => 'not-a-real-provider'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnonymousStartIsRejected(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            '/api/streaming/link',
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['provider' => 'spotify'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
