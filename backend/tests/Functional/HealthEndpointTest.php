<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Health\DependencyCheckInterface;
use App\Service\Health\RedisCheck;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * AC-7.2 / AC-7.3: `GET /api/health` boots the kernel and hits the real database and Redis
 * (AC-7.2), and a failing dependency is simulated with a test double at the dependency-check seam
 * rather than by actually stopping a container (AC-7.3).
 */
final class HealthEndpointTest extends WebTestCase
{
    use JsonResponseTrait;

    public function testHealthyReturns200WithBothDependencies(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('json', (string) $client->getResponse()->headers->get('Content-Type'));

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('ok', $data['status']);
        self::assertSame('ok', $data['database']);
        self::assertSame('ok', $data['redis']);
    }

    public function testUnhealthyDependencyReturns503WithoutHiddingTheHealthyOnes(): void
    {
        $client = static::createClient();

        self::getContainer()->set(RedisCheck::class, new class implements DependencyCheckInterface {
            public function name(): string
            {
                return 'redis';
            }

            public function isHealthy(): bool
            {
                return false;
            }
        });

        $client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(503);

        $data = self::decodeJsonObject((string) $client->getResponse()->getContent());

        self::assertSame('error', $data['status']);
        self::assertSame('error', $data['redis'], 'the failing dependency is named');
        self::assertSame('ok', $data['database'], 'the healthy dependency is still reported, not hidden');
    }

    public function testHealthEndpointRequiresNoAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
        self::assertNotSame(403, $client->getResponse()->getStatusCode());
    }
}
