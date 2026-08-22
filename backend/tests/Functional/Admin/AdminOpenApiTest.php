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

    /**
     * AC-11.5 regression (devops-security-engineer review, 2026-08-22): NelmioCorsBundle's
     * `ConfigProvider::getOptions()` falls back to the `defaults` block for *any* path that doesn't
     * match an entry in `paths` — a `paths: { '^/api': null }` override does not exclude `/api` from
     * CORS, and does not scope CORS *to* `/api` either, since a null/empty per-path override just
     * merges back onto `defaults` (`array_merge($this->defaults, $options)`), which is exactly what
     * an unmatched path already gets. A previous version of `nelmio_cors.yaml` had that shape and,
     * verified live against a running stack, still granted `/admin` the same
     * `Access-Control-Allow-Credentials: true` + matching `Access-Control-Allow-Origin` treatment as
     * `/api` the moment a request's Origin matched `CORS_ALLOW_ORIGIN` — despite the file's own
     * comment claiming the opposite. `defaults` must be the restrictive baseline and the permissive
     * policy opted in only under `paths: '^/api'`.
     */
    public function testAdminPrefixGetsNoCorsGrant(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'OPTIONS',
            '/admin',
            server: [
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        self::assertFalse(
            $client->getResponse()->headers->has('Access-Control-Allow-Origin'),
            'CORS must grant nothing on /admin, even for an Origin that would be allowed on /api',
        );
        self::assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Credentials'));
    }
}
