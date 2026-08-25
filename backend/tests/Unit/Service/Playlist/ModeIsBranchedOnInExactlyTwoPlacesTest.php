<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Playlist;

use PHPUnit\Framework\TestCase;

/**
 * D-188/AC-7.2 (docs/specs/2026-08-25-playlist-normal-mode.md): the mode is branched on in EXACTLY
 * two places — `Stage/SetlistSelectionStage.php` and `Stage/ReviewStage.php` — and nowhere else in
 * `App\Service\Playlist\`. A third occurrence is exactly the "parallel implementation" prompt 17's
 * own brief warns against.
 */
final class ModeIsBranchedOnInExactlyTwoPlacesTest extends TestCase
{
    private const array ALLOWED_FILES = [
        'Service/Playlist/Stage/SetlistSelectionStage.php',
        'Service/Playlist/Stage/ReviewStage.php',
    ];

    /** Matches `$job->getMode() === JobMode::Normal`, `JobMode::Normal === $job->getMode()`, and the `!==` forms. */
    private const string PATTERN = '/getMode\(\)\s*(===|!==)\s*JobMode::(Normal|Fast)|JobMode::(Normal|Fast)\s*(===|!==)\s*\$?\w+(->getMode\(\))?/';

    public function testModeIsBranchedOnInExactlyTwoFiles(): void
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
            if (1 !== preg_match(self::PATTERN, $contents)) {
                continue;
            }

            $isAllowed = false;
            foreach (self::ALLOWED_FILES as $allowed) {
                if (str_contains($realPath, '/src/'.$allowed)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                $offenders[] = $realPath;
            }
        }

        self::assertSame([], $offenders, "AC-7.2 violation — mode branched on outside the two allowed stages:\n".implode("\n", $offenders));
    }

    public function testBothAllowedFilesStillExistAndActuallyBranchOnMode(): void
    {
        // Guards against the scan above silently passing because someone renamed/deleted a stage —
        // both files must exist AND actually contain the pattern, or this test fails loudly.
        $srcRoot = \dirname(__DIR__, 4).'/src/';

        foreach (self::ALLOWED_FILES as $allowed) {
            $path = $srcRoot.$allowed;
            self::assertFileExists($path);
            $contents = (string) file_get_contents($path);
            self::assertSame(1, preg_match(self::PATTERN, $contents), \sprintf('%s no longer branches on JobMode — is Normal mode\'s guard still there?', $allowed));
        }
    }
}
