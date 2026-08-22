<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Streaming;

use PHPUnit\Framework\TestCase;

/**
 * D-82, AC-9.4: the structural enforcement of `CLAUDE.md`'s streaming-port rule — "no `Spotify`
 * symbol may appear anywhere outside `backend/src/Service/Streaming/Spotify/`". Written FIRST
 * (suggested implementation order, step 1), against an empty `Service/Streaming/` directory, so it
 * fails on the very first leak rather than after the leak already has consumers.
 *
 * Deliberately case-sensitive and token-based (not a PHP parser): a class, interface, trait, enum,
 * constant, function or type reference all contain the literal substring `Spotify` somewhere in
 * their declaration or reference, so a plain substring scan catches all of them without needing to
 * understand PHP syntax. Configuration/`.env` variable names (`SPOTIFY_CLIENT_ID`, …) are outside
 * the ban (D-82) — they are not PHP symbols and cannot be renamed away — but this test still only
 * scans `src/`, and `App\Kernel`/`config/*.yaml` are not `src/` PHP files, so that carve-out never
 * needs its own exception here.
 */
final class SpotifySymbolIsolationTest extends TestCase
{
    private const string ALLOWED_DIRECTORY = 'Service/Streaming/Spotify';

    public function testNoSpotifySymbolAppearsOutsideItsAdapterDirectory(): void
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
            if (str_contains($contents, 'Spotify')) {
                $offenders[] = $realPath;
            }
        }

        self::assertSame([], $offenders, 'D-82/AC-9.4 violation — the word "Spotify" may only appear under src/'.self::ALLOWED_DIRECTORY."/:\n".implode("\n", $offenders));
    }
}
