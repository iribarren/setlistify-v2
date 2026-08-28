<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use PHPUnit\Framework\TestCase;

/**
 * AC-7.3 (docs/specs/2026-08-27-instant-setlist-refresh.md): `User::$instantRefreshGrantedAt` is
 * read ONLY by `App\Security\Voter\InstantRefreshVoter` — no processor, handler, controller or
 * template tests it directly. Same static-scan enforcement shape as
 * `App\Tests\Unit\Service\Setlist\SetlistGatewayIsOnlyDoorTest` (D-58).
 *
 * The entity's declaration (`Entity/User.php`, which defines the property and its getter) and the
 * voter itself are the only allowed files. Every other caller must go through
 * `Security::isGranted('CAN_REFRESH_SETLIST_NOW', $user)` instead.
 */
final class InstantRefreshVoterOnlyReaderTest extends TestCase
{
    private const string ALLOWED_VOTER_FILE = 'Security/Voter/InstantRefreshVoter.php';
    private const string ALLOWED_ENTITY_FILE = 'Entity/User.php';
    private const string TOKEN = 'InstantRefreshGrantedAt';

    public function testOnlyTheVoterAndTheEntityItselfReferenceTheGrantTimestamp(): void
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

            if (str_ends_with($realPath, '/src/'.self::ALLOWED_VOTER_FILE) || str_ends_with($realPath, '/src/'.self::ALLOWED_ENTITY_FILE)) {
                continue;
            }

            $contents = (string) file_get_contents($realPath);
            if (str_contains($contents, self::TOKEN)) {
                $offenders[] = $realPath;
            }
        }

        self::assertSame([], $offenders, "AC-7.3 violation — only InstantRefreshVoter (and User's own declaration) may reference instantRefreshGrantedAt:\n".implode("\n", $offenders));
    }
}
