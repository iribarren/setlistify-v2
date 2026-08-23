<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Streaming;

use PHPUnit\Framework\TestCase;

/**
 * D-147/T-ARCH-05: `SpotifyTrackMapper::naiveConfidence()` was deliberately provisional (D-83) and
 * is deleted outright, not deprecated, once prompt 12's scorer replaces it. This is a structural
 * guard against it — or a method sharing its name — quietly reappearing anywhere in `src/`.
 */
final class NaiveConfidenceIsGoneTest extends TestCase
{
    public function testNaiveConfidenceAppearsNowhereInSource(): void
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

            $contents = (string) file_get_contents($realPath);
            if (str_contains($contents, 'naiveConfidence')) {
                $offenders[] = $realPath;
            }
        }

        self::assertSame([], $offenders, "D-147 violation — 'naiveConfidence' must not appear anywhere in src/:\n".implode("\n", $offenders));
    }
}
