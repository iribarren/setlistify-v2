<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Functional\Auth\AuthWebTestCase;

/**
 * AC-11.1/AC-11.2/US-11: the backoffice is invisible to the OpenAPI spec — no path under the admin
 * prefix, and no schema for `AuditLogEntry` (the one admin-only entity a naive serializer walk
 * could otherwise reach).
 */
final class AdminOpenApiTest extends AuthWebTestCase
{
    public function testNoAdminPathInOpenApiSpec(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();

        $spec = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $paths = $spec['paths'] ?? null;
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'sanity check: the spec must have at least one real path');

        foreach (array_keys($paths) as $path) {
            self::assertIsString($path);
            self::assertStringStartsNotWith('/admin', $path, \sprintf('OpenAPI spec must not contain an admin path, found "%s"', $path));
        }
    }

    public function testNoAuditLogEntrySchemaInOpenApiSpec(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/docs.jsonopenapi');
        self::assertResponseIsSuccessful();

        $spec = self::decodeJsonObject((string) $client->getResponse()->getContent());
        $components = $spec['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);

        foreach (array_keys($schemas) as $schemaName) {
            self::assertIsString($schemaName);
            self::assertStringNotContainsStringIgnoringCase('AuditLogEntry', $schemaName);
        }
    }
}
