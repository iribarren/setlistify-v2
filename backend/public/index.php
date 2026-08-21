<?php

/**
 * Placeholder entrypoint.
 *
 * This feature (docs/specs/2026-08-21-monorepo-and-environments.md) ships infrastructure only:
 * the backend container must start and answer a health probe, but the Symfony application itself
 * is out of scope (prompt 01, docs/prompts/01-backend-skeleton.md replaces this file entirely).
 */

declare(strict_types=1);

header('Content-Type: application/json');

echo json_encode([
    'status' => 'ok',
    'service' => 'setlistify-backend',
    'note' => 'placeholder health endpoint — Symfony application not yet installed (prompt 01)',
]);
