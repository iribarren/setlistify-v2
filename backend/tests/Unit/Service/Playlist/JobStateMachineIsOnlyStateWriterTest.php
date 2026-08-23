<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Playlist;

use PHPUnit\Framework\TestCase;

/**
 * D-159: no class other than `App\Service\Playlist\JobStateMachine` may call
 * `PlaylistGenerationJob::setStateInternal()` — a static scan of every PHP file under `src/` outside
 * `JobStateMachine.php` itself, the same technique `SetlistGatewayIsOnlyDoorTest` uses for D-58.
 */
final class JobStateMachineIsOnlyStateWriterTest extends TestCase
{
    /** @var list<string> the writer itself, and the entity that DEFINES the method (not a caller) */
    private const array ALLOWED_FILES = ['Service/Playlist/JobStateMachine.php', 'Entity/PlaylistGenerationJob.php'];

    public function testNoFileOtherThanJobStateMachineCallsSetStateInternal(): void
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

            $isAllowed = false;
            foreach (self::ALLOWED_FILES as $allowed) {
                if (str_contains($realPath, '/src/'.$allowed)) {
                    $isAllowed = true;
                    break;
                }
            }
            if ($isAllowed) {
                continue;
            }

            $contents = (string) file_get_contents($realPath);
            if (str_contains($contents, 'setStateInternal')) {
                $offenders[] = $realPath;
            }
        }

        self::assertSame([], $offenders, "D-159 violation — only JobStateMachine may call setStateInternal():\n".implode("\n", $offenders));
    }
}
