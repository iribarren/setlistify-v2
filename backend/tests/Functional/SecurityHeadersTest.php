<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-9: security headers are applied globally (AC-9.4, AC-9.6), never expose the framework or
 * server version (AC-9.7), and don't break `/api/docs` (AC-9.5).
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testApiResponseCarriesSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertNotNull($headers->get('Content-Security-Policy'));
        self::assertFalse($headers->has('X-Powered-By'));
    }

    public function testDocsPageStillRendersWithSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));

        $headers = $client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertNotNull($headers->get('Content-Security-Policy'));
    }
}
