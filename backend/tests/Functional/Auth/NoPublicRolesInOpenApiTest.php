<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

/**
 * AC-10.3, second half: enumerates the generated OpenAPI document's **write operations'
 * request-body schemas** and fails if any of them declares a `roles` property. Deliberately scoped
 * to request bodies, not every schema — `Me`'s response legitimately reports the caller's own
 * roles (AC-8.1); AC-10.3 is about what a client can *send*, not what it can read back.
 *
 * Complements {@see RegistrationTest}'s behavioural assertions — this one fails the moment someone
 * adds a `roles` field to any input DTO, before a behavioural test would even need to be written.
 */
final class NoPublicRolesInOpenApiTest extends AuthWebTestCase
{
    public function testNoWriteOperationRequestBodyExposesARolesProperty(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        self::assertResponseIsSuccessful();
        $spec = self::decodeJsonObject((string) $client->getResponse()->getContent());

        $schemas = self::asArray($spec['components'] ?? null)['schemas'] ?? null;
        self::assertIsArray($schemas);

        $paths = self::asArray($spec['paths'] ?? null);

        $requestBodySchemaNames = [];

        foreach ($paths as $path => $operations) {
            foreach (self::asArray($operations) as $method => $operation) {
                if (!\in_array(strtolower((string) $method), ['post', 'put', 'patch'], true)) {
                    continue;
                }

                $operation = self::asArray($operation);
                if (!\is_array($operation['requestBody'] ?? null)) {
                    // e.g. EmailVerificationResendAction (input: false) — nothing to check.
                    continue;
                }

                $requestBody = self::asArray($operation['requestBody']);
                $content = self::asArray($requestBody['content'] ?? null);
                $mediaType = self::asArray($content['application/ld+json'] ?? $content['application/json'] ?? null);
                $schema = self::asArray($mediaType['schema'] ?? null);
                $ref = $schema['$ref'] ?? null;

                if (null === $ref) {
                    continue;
                }

                self::assertIsString($ref);
                $requestBodySchemaNames[] = [(string) $path, (string) $method, self::schemaNameFromRef($ref)];
            }
        }

        self::assertNotEmpty($requestBodySchemaNames, 'sanity check: at least one write operation with a request body must be found');

        foreach ($requestBodySchemaNames as [$path, $method, $schemaName]) {
            $schema = $schemas[$schemaName] ?? null;
            self::assertIsArray($schema, \sprintf('schema "%s" referenced by %s %s must exist', $schemaName, $method, $path));

            $properties = self::asArray($schema['properties'] ?? null);

            self::assertArrayNotHasKey(
                'roles',
                $properties,
                \sprintf('%s %s\'s request body ("%s") exposes a "roles" property — AC-10.3 forbids this', strtoupper($method), $path, $schemaName),
            );
        }
    }

    private static function schemaNameFromRef(string $ref): string
    {
        $parts = explode('/', $ref);

        return (string) end($parts);
    }

    /** @return array<array-key, mixed> */
    private static function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }
}
