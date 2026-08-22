<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\Provider\ProviderConfigProvider;

/**
 * `GET /api/config/providers` (US-6). Public, unauthenticated — the Expo client's startup read of
 * which providers are on and how playback should render (D-101). **Part of the public API
 * contract** (AC-6.7) — unlike the `/admin` screen that edits the same data, which never enters the
 * OpenAPI spec (`CLAUDE.md`: "the backoffice is not part of the contract").
 *
 * Read-only by construction: only a `GetCollection` operation is declared, so no `POST`/`PATCH`/
 * `PUT`/`DELETE` is ever exposed on this route (AC-6.6).
 *
 * `headers: ['Cache-Control' => 'no-store']` (D-98, AC-6.5) — the endpoint is cheap because of
 * `ProviderRegistry`'s server-side Redis snapshot, which a write can invalidate; an HTTP cache in
 * front of a kill switch is the kill switch's failure mode.
 */
#[ApiResource(
    shortName: 'ProviderConfig',
    description: 'Which streaming providers are offered right now, and how playback should render — read by the client at startup (US-6).',
    operations: [
        new GetCollection(
            uriTemplate: '/config/providers',
            output: ProviderConfigOutput::class,
            provider: ProviderConfigProvider::class,
            security: "is_granted('PUBLIC_ACCESS')",
            headers: ['Cache-Control' => 'no-store'],
        ),
    ],
)]
final readonly class ProviderConfigResource
{
}
