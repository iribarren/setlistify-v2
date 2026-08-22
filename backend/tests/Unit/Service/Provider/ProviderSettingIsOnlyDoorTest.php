<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;

/**
 * AC-10.1 (docs/specs/2026-08-22-backoffice-provider-configuration.md): only `ProviderRegistry`
 * (reads) and `ProviderSettingWriter` (writes) may reference `App\Entity\ProviderSetting` or
 * `App\Repository\ProviderSettingRepository` — copies `SetlistGatewayIsOnlyDoorTest`'s shape
 * exactly (allowlist of permitted files, static substring scan, same reasoning about why a
 * container-based check can't do this job in `test` env).
 *
 * One disclosed exception, recorded in `ProviderSetting`'s own docblock: `App\Controller\Admin\
 * ProviderSettingCrudController` must name the entity for EasyAdmin's `getEntityFqcn()` — EasyAdmin
 * has no other way to bind a CRUD screen to an entity. That controller never persists the entity
 * itself; every write goes through `ProviderSettingWriter` (see its `updateEntity()` override).
 */
final class ProviderSettingIsOnlyDoorTest extends TestCase
{
    /** @var list<string> */
    private const array ALLOWED_FILES = [
        '/src/Service/Provider/ProviderRegistry.php',
        '/src/Service/Provider/ProviderSettingWriter.php',
        '/src/Controller/Admin/ProviderSettingCrudController.php',
        // The entity and repository files themselves obviously reference their own class.
        '/src/Entity/ProviderSetting.php',
        '/src/Repository/ProviderSettingRepository.php',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_TOKENS = [
        'ProviderSetting',
        'ProviderSettingRepository',
    ];

    public function testNoFileOutsideTheAllowlistReferencesProviderSettingOrItsRepository(): void
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
                if (str_ends_with($realPath, $allowed)) {
                    $isAllowed = true;
                    break;
                }
            }
            if ($isAllowed) {
                continue;
            }

            $contents = (string) file_get_contents($realPath);
            foreach (self::FORBIDDEN_TOKENS as $token) {
                // Word-boundary match, not a plain substring scan — "ProviderSetting" must not
                // false-positive on "ProviderSettingWriter"/"ProviderSettingValidationException"/
                // "ProviderSettingCrudController", which are different, legitimate classes.
                if (1 === preg_match('/\b'.preg_quote($token, '/').'\b/', $contents)) {
                    $offenders[] = \sprintf('%s references "%s"', $realPath, $token);
                }
            }
        }

        self::assertSame([], $offenders, "AC-10.1 violation — only ProviderRegistry, ProviderSettingWriter and (for EasyAdmin's getEntityFqcn()) ProviderSettingCrudController may reference ProviderSetting/ProviderSettingRepository:\n".implode("\n", $offenders));
    }
}
