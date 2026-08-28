<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setlist;

use PHPUnit\Framework\TestCase;

/**
 * AC-2.8 (docs/specs/2026-08-27-instant-setlist-refresh.md): `BandIdentityResolver::forceResolve()`,
 * `resolveAmbiguousChoice()` and `SetlistGateway`'s force-live methods
 * (`refreshArtistSearch()`/`refreshArtistSetlistsPageOne()`) are called ONLY from the refresh
 * Messenger handler and the two refresh API processors — no read path, no state provider, no other
 * service. Same structural-enforcement shape as `SetlistGatewayIsOnlyDoorTest` (D-58).
 */
final class ForceLiveCallersAreRestrictedTest extends TestCase
{
    /** @var list<string> */
    private const array ALLOWED_CALLER_FILES = [
        'MessageHandler/RefreshBandSetlistsHandler.php',
        'State/Processor/Setlist/TriggerSetlistRefreshProcessor.php',
        'State/Processor/Setlist/ResolveBandIdentityProcessor.php',
    ];

    /** Their own declaration files never "call" themselves for this test's purposes. */
    private const string ALLOWED_DECLARATION_1 = 'Service/Setlist/BandIdentityResolver.php';
    private const string ALLOWED_DECLARATION_2 = 'Service/Setlist/SetlistGateway.php';

    /** @var list<string> */
    private const array FORBIDDEN_TOKENS = [
        '->forceResolve(',
        '->resolveAmbiguousChoice(',
        '->refreshArtistSearch(',
        '->refreshArtistSetlistsPageOne(',
    ];

    public function testOnlyTheRefreshHandlerAndProcessorsCallTheForceLivePath(): void
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

            if (str_ends_with($realPath, '/src/'.self::ALLOWED_DECLARATION_1) || str_ends_with($realPath, '/src/'.self::ALLOWED_DECLARATION_2)) {
                continue;
            }

            $isAllowedCaller = false;
            foreach (self::ALLOWED_CALLER_FILES as $allowed) {
                if (str_ends_with($realPath, '/src/'.$allowed)) {
                    $isAllowedCaller = true;
                    break;
                }
            }
            if ($isAllowedCaller) {
                continue;
            }

            $contents = (string) file_get_contents($realPath);
            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($contents, $token)) {
                    $offenders[] = \sprintf('%s calls "%s"', $realPath, $token);
                }
            }
        }

        self::assertSame([], $offenders, "AC-2.8 violation — only the refresh handler/processors may call the force-live path:\n".implode("\n", $offenders));
    }
}
