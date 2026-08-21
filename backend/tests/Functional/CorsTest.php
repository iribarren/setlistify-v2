<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-9: CORS is driven by CORS_ALLOW_ORIGIN, never a wildcard (AC-9.1, AC-9.2); a preflight from
 * an allowed origin succeeds, one from a disallowed origin gets no permissive CORS headers
 * (AC-9.3).
 */
final class CorsTest extends WebTestCase
{
    public function testPreflightFromAllowedOriginSucceeds(): void
    {
        $client = static::createClient();
        $client->request(
            'OPTIONS',
            '/api/health',
            server: [
                'HTTP_ORIGIN' => 'http://localhost:8081',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $response = $client->getResponse();

        self::assertSame('http://localhost:8081', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testPreflightFromDisallowedOriginGetsNoPermissiveCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request(
            'OPTIONS',
            '/api/health',
            server: [
                'HTTP_ORIGIN' => 'https://evil.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $response = $client->getResponse();

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
