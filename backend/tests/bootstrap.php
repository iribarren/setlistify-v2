<?php

require dirname(__DIR__).'/vendor/autoload.php';

// No .env / .env.test is committed (D-5, docs/specs/2026-08-21-backend-skeleton.md) — the `test`
// environment's overrides (APP_ENV=test, SHELL_VERBOSITY) live in phpunit.xml.dist, and
// infrastructure values (DATABASE_URL, REDIS_URL, ...) come from the real process environment,
// exactly as they do outside tests.
if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
