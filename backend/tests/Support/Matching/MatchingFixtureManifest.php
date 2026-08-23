<?php

declare(strict_types=1);

namespace App\Tests\Support\Matching;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and checksums `tests/Fixtures/matching/manifest.yaml` — the frozen fixture set behind
 * `App\Tests\Matching\MatchingQualityHarnessTest` (spec 12 §9, D-122).
 *
 * Kept as a small standalone loader (not a service) deliberately: the harness must not depend on
 * the very matching code it evaluates for something as basic as "can I read my own fixtures".
 */
final class MatchingFixtureManifest
{
    public static function manifestPath(): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/matching/manifest.yaml';
    }

    public static function catalogDir(): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/matching/catalog';
    }

    public static function checksumPath(): string
    {
        return \dirname(__DIR__, 2).'/Fixtures/matching/manifest.sha256';
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = self::manifestPath();
        \assert(is_file($path));

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed;
    }

    /**
     * Every non-song-only-marker (`.gitkeep`) file is excluded — an otherwise-empty `catalog/`
     * directory must hash identically to "no catalog fixtures exist" so creating the directory
     * itself never trips the freeze check.
     *
     * @return list<string> catalog fixture file paths, relative to `catalogDir()`, sorted
     */
    public static function catalogFiles(): array
    {
        $dir = self::catalogDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('.gitkeep' === $file->getFilename()) {
                continue;
            }
            $realPath = $file->getRealPath();
            \assert(false !== $realPath);
            $files[] = ltrim(str_replace($dir, '', $realPath), '/');
        }

        sort($files);

        return $files;
    }

    /**
     * The checksum covers `manifest.yaml`'s bytes AND every file under `catalog/` (path + contents)
     * — so dropping in real capture fixtures without touching `manifest.yaml` itself still trips the
     * freeze check, exactly as it should (arming the gate is precisely the kind of change the freeze
     * exists to make deliberate).
     */
    public static function computeChecksum(): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, (string) file_get_contents(self::manifestPath()));

        foreach (self::catalogFiles() as $relativePath) {
            hash_update($hash, $relativePath);
            hash_update($hash, (string) file_get_contents(self::catalogDir().'/'.$relativePath));
        }

        return hash_final($hash);
    }

    public static function readCommittedChecksum(): ?string
    {
        $path = self::checksumPath();
        if (!is_file($path)) {
            return null;
        }

        return trim((string) file_get_contents($path));
    }
}
