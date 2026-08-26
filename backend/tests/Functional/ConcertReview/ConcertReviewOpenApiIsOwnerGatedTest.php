<?php

declare(strict_types=1);

namespace App\Tests\Functional\ConcertReview;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-4.4: there is no endpoint, anywhere in the generated OpenAPI document, that returns a review
 * belonging to a user other than the authenticated one. A static test greps the generated spec for
 * the review schema and asserts every operation carrying it is under an owner-gated path — here,
 * "owner-gated" means literally scoped under `/concerts/{concertId}/...` (D-229: the parent concert
 * is always resolved through the owner-filtered `ConcertLocator` first), and never under a bare
 * `/concert-reviews` or `/reviews` collection, which would have no such gate (D-228 forbids that
 * shape existing at all, but this test would catch it if one were ever added).
 */
final class ConcertReviewOpenApiIsOwnerGatedTest extends KernelTestCase
{
    public function testEveryOperationCarryingTheReviewSchemaIsUnderConcertsConcertId(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(OpenApiFactoryInterface::class);
        $openApi = $factory([]);

        $reviewSchemaNames = ['ConcertReview.ConcertReviewOutput.jsonld', 'ConcertReviewOutput'];

        $offenders = [];
        $foundAny = false;

        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            foreach (['get', 'put', 'post', 'patch', 'delete'] as $method) {
                $getter = 'get'.ucfirst($method);
                $operation = $pathItem->$getter();
                if (null === $operation) {
                    continue;
                }

                $responses = $operation->getResponses() ?? [];
                foreach ($responses as $response) {
                    $content = $response->getContent();
                    if (!$content instanceof \ArrayObject) {
                        continue;
                    }

                    foreach ($content as $mediaType) {
                        if (!$mediaType instanceof MediaType) {
                            continue;
                        }

                        $schema = $mediaType->getSchema();
                        $ref = $schema instanceof \ArrayObject ? ($schema['$ref'] ?? null) : null;
                        if (!\is_string($ref)) {
                            continue;
                        }

                        foreach ($reviewSchemaNames as $name) {
                            if (str_contains($ref, $name)) {
                                $foundAny = true;
                                if (!str_starts_with($path, '/concerts/{concertId}') && !str_starts_with($path, '/api/concerts/{concertId}')) {
                                    $offenders[] = \sprintf('%s %s references %s', strtoupper($method), $path, $ref);
                                }
                            }
                        }
                    }
                }
            }
        }

        self::assertTrue($foundAny, 'The review schema must appear at least once in the generated OpenAPI document — otherwise this test is not testing anything.');
        self::assertSame([], $offenders, "Every operation carrying the review schema must live under /concerts/{concertId}/... :\n".implode("\n", $offenders));
    }

    public function testNoReviewCollectionEndpointExists(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(OpenApiFactoryInterface::class);
        $openApi = $factory([]);

        $forbiddenPaths = ['/reviews', '/concert_reviews', '/concert-reviews', '/api/reviews'];

        foreach (array_keys($openApi->getPaths()->getPaths()) as $path) {
            self::assertNotContains($path, $forbiddenPaths, \sprintf('No bare review collection endpoint may exist (D-228); found one at %s.', $path));
        }
    }
}
