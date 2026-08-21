<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test-only route (wired only `when@test`, see config/routes.yaml) that always throws, carrying a
 * deliberately sensitive-looking message. Exists so AC-6.2/AC-6.3 can assert, against a real HTTP
 * response, that a 500 comes back as RFC 7807 and that nothing in the message, file path or class
 * name below ever reaches a `debug: false` response.
 */
final class ThrowingController
{
    #[Route('/api/_test/throw', name: 'test_throw', methods: ['GET'])]
    public function __invoke(): Response
    {
        throw new \RuntimeException('DID-NOT-LEAK: /var/www/secret-config.php DATABASE_URL=postgresql://setlistify:changeme@postgres:5432/setlistify');
    }
}
