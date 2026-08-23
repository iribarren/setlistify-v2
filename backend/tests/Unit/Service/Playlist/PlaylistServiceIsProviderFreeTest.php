<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Playlist;

use PHPUnit\Framework\TestCase;

/**
 * T-ARCH-01, D-159: `App\Service\Playlist\` never names a provider and never hardcodes a provider
 * key literal — the pipeline reaches a provider only through `StreamingProviderInterface` and
 * `StreamingProviderLocator`, keyed by a RUNTIME string. Adding a second provider (prompt 18) must
 * never touch this directory.
 */
final class PlaylistServiceIsProviderFreeTest extends TestCase
{
    /** @var list<string> */
    private const array FORBIDDEN_PROVIDER_NAMES = ['Spotify', 'YouTube', 'Apple'];

    /** @var list<string> known provider key() literals — never hardcoded outside their own adapter. */
    private const array FORBIDDEN_PROVIDER_KEYS = ["'spotify'", '"spotify"', "'youtube'", '"youtube"', "'apple'", '"apple"'];

    public function testNoProviderSymbolOrKeyLiteralAppearsUnderServicePlaylist(): void
    {
        $playlistDir = \dirname(__DIR__, 4).'/src/Service/Playlist';
        self::assertDirectoryExists($playlistDir);

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($playlistDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $realPath = $file->getRealPath();
            \assert(false !== $realPath);
            $contents = (string) file_get_contents($realPath);

            foreach (self::FORBIDDEN_PROVIDER_NAMES as $name) {
                if (str_contains($contents, $name)) {
                    $offenders[] = \sprintf('%s contains provider name "%s"', $realPath, $name);
                }
            }

            foreach (self::FORBIDDEN_PROVIDER_KEYS as $key) {
                if (str_contains($contents, $key)) {
                    $offenders[] = \sprintf('%s contains provider key literal %s', $realPath, $key);
                }
            }
        }

        self::assertSame([], $offenders, "D-159/T-ARCH-01 violation:\n".implode("\n", $offenders));
    }
}
