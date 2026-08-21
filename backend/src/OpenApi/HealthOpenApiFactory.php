<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Decorates API Platform's OpenAPI factory to add the `503` response to `GET /api/health`
 * (AC-3.3). A `#[Get]` operation attribute can only describe one static response, and overriding
 * it there would replace — not extend — the auto-generated `200` response and the `Health` schema
 * it references. Decorating the factory lets both responses share that same generated schema.
 */
final readonly class HealthOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $paths = $openApi->getPaths();
        $healthPathItem = $paths->getPath('/api/health');
        $getOperation = $healthPathItem?->getGet();

        if (null === $healthPathItem || null === $getOperation) {
            return $openApi;
        }

        $responses = $getOperation->getResponses() ?? [];
        $okResponse = $responses['200'] ?? null;
        $schemaContent = $okResponse?->getContent();

        $unavailableResponse = new Response(
            description: 'At least one dependency is unhealthy. The body reports the status of every dependency, healthy and unhealthy alike.',
            content: $schemaContent instanceof \ArrayObject ? $schemaContent : new \ArrayObject([
                'application/ld+json' => new MediaType(),
            ]),
        );

        $paths->addPath('/api/health', $healthPathItem->withGet(
            $getOperation->addResponse($unavailableResponse, 503),
        ));

        return $openApi->withPaths($paths);
    }
}
