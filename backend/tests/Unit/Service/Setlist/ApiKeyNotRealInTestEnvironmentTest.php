<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setlist;

use PHPUnit\Framework\TestCase;

/**
 * AC-13.2: fails the build if `SETLISTFM_API_KEY` is set to a real-looking value in the test
 * environment configuration. setlist.fm API keys are 32-character lowercase hex strings; a
 * deliberately non-hex, human-readable placeholder in `phpunit.xml.dist` is what keeps a real key
 * from ever being pasted in by accident and silently spending live budget from CI.
 */
final class ApiKeyNotRealInTestEnvironmentTest extends TestCase
{
    public function testTestEnvironmentApiKeyIsNotRealLooking(): void
    {
        $value = $_ENV['SETLISTFM_API_KEY'] ?? getenv('SETLISTFM_API_KEY');
        self::assertIsString($value, 'SETLISTFM_API_KEY must be set in the test environment.');

        self::assertDoesNotMatchRegularExpression(
            '/^[0-9a-f]{32}$/',
            $value,
            'SETLISTFM_API_KEY in the test environment looks like a real setlist.fm key (32 lowercase hex chars) — replace it with an obvious placeholder (AC-13.2).',
        );
    }
}
