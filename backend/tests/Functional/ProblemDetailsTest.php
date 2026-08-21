<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-6: every error under `/api` is RFC 7807 `application/problem+json`, configured globally
 * (AC-6.1), and a production-like response (`debug: false`, AC-6.3) never contains a stack trace,
 * exception class, file path or SQL fragment — while a debug response is allowed to be richer
 * (AC-6.4).
 */
final class ProblemDetailsTest extends WebTestCase
{
    use JsonResponseTrait;

    public function testUnknownRouteReturns404AsProblemDetails(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/this-route-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame(404, $data['status']);
        self::assertArrayHasKey('title', $data);
        self::assertArrayHasKey('type', $data);
        self::assertArrayHasKey('detail', $data);
    }

    public function testWrongMethodReturns405AsProblemDetails(): void
    {
        $client = static::createClient();
        $client->request('DELETE', '/api/health');

        self::assertResponseStatusCodeSame(405);
        self::assertStringContainsString('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame(405, $data['status']);
    }

    public function testForcedExceptionReturns500AsProblemDetails(): void
    {
        $client = static::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/api/_test/throw');

        self::assertResponseStatusCodeSame(500);
        self::assertStringContainsString('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame(500, $data['status']);
        self::assertArrayHasKey('title', $data);
    }

    /**
     * AC-6.3: with the kernel's debug flag off (what `APP_ENV=prod` runs with), the deliberately
     * thrown exception's message, class name and file path must not reach the response body.
     */
    public function testForcedExceptionDoesNotLeakInternalsWhenDebugIsOff(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->catchExceptions(true);
        $client->request('GET', '/api/_test/throw');

        self::assertResponseStatusCodeSame(500);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('DID-NOT-LEAK', $body);
        self::assertStringNotContainsString('secret-config.php', $body);
        self::assertStringNotContainsString('DATABASE_URL', $body);
        self::assertStringNotContainsString('ThrowingController', $body);
        self::assertStringNotContainsString(__DIR__, $body);
        self::assertStringNotContainsString('.php', $body);

        $data = self::decodeJsonObject($body);
        self::assertArrayNotHasKey('trace', $data);
        self::assertArrayNotHasKey('file', $data);
        self::assertArrayNotHasKey('line', $data);
        self::assertArrayNotHasKey('class', $data);
    }

    /**
     * AC-6.4: the richer debug output stays available when the kernel *is* in debug mode — the
     * restriction above is environment-driven, not a permanent loss of ergonomics.
     */
    public function testForcedExceptionIncludesDebugDetailWhenDebugIsOn(): void
    {
        $client = static::createClient(['debug' => true]);
        $client->catchExceptions(true);
        $client->request('GET', '/api/_test/throw');

        self::assertResponseStatusCodeSame(500);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('DID-NOT-LEAK', $body);
    }
}
