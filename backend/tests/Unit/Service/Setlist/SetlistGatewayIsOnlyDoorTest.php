<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setlist;

use PHPUnit\Framework\TestCase;

/**
 * D-58, AC-6.5, R-5: `App\Service\Setlist\SetlistFmClient` is the only class allowed to hold the
 * outbound HTTP transport. This is enforced structurally, not by convention — a static scan of
 * every PHP file under `src/` outside `App\Service\Setlist\`, asserting none of them references
 * `SetlistFmClient` (by class name, "setlistfm.client" service id, or the
 * `HttpClientInterface $setlistfmClient` autowiring alias Symfony's scoped-client naming
 * convention generates for `config/packages/setlistfm.yaml`).
 *
 * A container-based `has()`/`get()` check can't do this job: `config/packages/framework.yaml`'s
 * `when@test: framework: test: true` makes every service public in the test container, so the
 * production access boundary (private-by-default) is invisible from a booted test kernel. A static
 * source scan is what's left, and it's exactly the AC-6.5 wording — "enforced by a static test".
 */
final class SetlistGatewayIsOnlyDoorTest extends TestCase
{
    private const string ALLOWED_DIRECTORY = 'Service/Setlist';

    /** @var list<string> */
    private const array FORBIDDEN_TOKENS = [
        'SetlistFmClient',
        'setlistfm.client',
        'setlistfmClient',
        'SETLISTFM_API_KEY',
    ];

    public function testNoFileOutsideServiceSetlistReferencesTheHttpTransportOrTheApiKey(): void
    {
        $srcDir = \dirname(__DIR__, 4).'/src';
        self::assertDirectoryExists($srcDir);

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $realPath = $file->getRealPath();
            \assert(false !== $realPath);

            if (str_contains($realPath, '/src/'.self::ALLOWED_DIRECTORY.'/')) {
                continue;
            }

            $contents = (string) file_get_contents($realPath);
            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($contents, $token)) {
                    $offenders[] = \sprintf('%s references "%s"', $realPath, $token);
                }
            }
        }

        self::assertSame([], $offenders, "D-58/AC-6.5 violation — only App\\Service\\Setlist\\ may reference the setlist.fm HTTP transport or its API key:\n".implode("\n", $offenders));
    }
}
