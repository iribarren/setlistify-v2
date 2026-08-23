<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Matching;

use PHPUnit\Framework\TestCase;

/**
 * T-ARCH-02, D-159: `App\Service\Matching\` never names a provider and never hardcodes a provider
 * key literal — per-provider calibration is a `profiles.yaml` entry keyed by a RUNTIME string
 * (`StreamingProviderInterface::key()`), never a PHP branch (D-118). Adding a second provider's
 * calibration must never touch this directory.
 *
 * Deliberately case-insensitive on provider names (a docblock mentioning "Spotify" leaks the seam
 * just as much as a class reference does — the same lesson recorded for the backoffice in
 * project_backoffice_provider_configuration.md) and exact-match on known provider key literals so
 * ordinary English words are never false positives.
 */
final class MatchingServiceIsProviderFreeTest extends TestCase
{
    /** @var list<string> */
    private const array FORBIDDEN_PROVIDER_NAMES = ['Spotify', 'YouTube', 'Apple'];

    /** @var list<string> known provider key() literals — never hardcoded outside their own adapter. */
    private const array FORBIDDEN_PROVIDER_KEYS = ["'spotify'", '"spotify"', "'youtube'", '"youtube"', "'apple'", '"apple"'];

    public function testNoProviderSymbolOrKeyLiteralAppearsUnderServiceMatching(): void
    {
        $matchingDir = \dirname(__DIR__, 4).'/src/Service/Matching';
        self::assertDirectoryExists($matchingDir);

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($matchingDir, \FilesystemIterator::SKIP_DOTS));
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

        self::assertSame([], $offenders, "D-159/T-ARCH-02 violation:\n".implode("\n", $offenders));
    }
}
