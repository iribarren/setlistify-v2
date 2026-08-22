<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;

/**
 * AC-9.4 (docs/specs/2026-08-22-backoffice-provider-configuration.md): no class added in this
 * branch (`src/Entity/ProviderSetting.php`, `src/Repository/ProviderSettingRepository.php`,
 * `src/Service/Provider/*`, `src/ApiResource/ProviderConfig*.php`, `src/State/Provider/
 * ProviderConfigProvider.php`, `src/Controller/Admin/ProviderSettingCrudController.php`) may
 * reference a provider credential — `SPOTIFY_CLIENT_ID`/`SPOTIFY_CLIENT_SECRET`/any `%env(...)%`
 * credential parameter, or the literal capitalized provider name (which would also trip
 * `SpotifySymbolIsolationTest`, AC-9.5).
 */
final class NoCredentialInjectionTest extends TestCase
{
    /** @var list<string> */
    private const array FILES = [
        '/src/Entity/ProviderSetting.php',
        '/src/Repository/ProviderSettingRepository.php',
        '/src/Service/Provider/PlaybackMode.php',
        '/src/Service/Provider/ProviderAvailability.php',
        '/src/Service/Provider/ProviderConfig.php',
        '/src/Service/Provider/ProviderDisabledException.php',
        '/src/Service/Provider/ProviderRegistry.php',
        '/src/Service/Provider/ProviderSettingValidationException.php',
        '/src/Service/Provider/ProviderSettingWriter.php',
        '/src/ApiResource/ProviderConfigOutput.php',
        '/src/ApiResource/ProviderConfigResource.php',
        '/src/State/Provider/ProviderConfigProvider.php',
        '/src/Controller/Admin/ProviderSettingCrudController.php',
    ];

    /** @var list<string> */
    private const array FORBIDDEN_TOKENS = [
        'SPOTIFY_CLIENT_ID',
        'SPOTIFY_CLIENT_SECRET',
        'YOUTUBE_CLIENT_ID',
        'YOUTUBE_CLIENT_SECRET',
        'APPLE_PRIVATE_KEY',
        '%env(',
        'Spotify',
        'YouTube',
    ];

    public function testNoBranchFileReferencesACredentialOrACapitalizedProviderName(): void
    {
        $root = \dirname(__DIR__, 4);
        $offenders = [];

        foreach (self::FILES as $relativePath) {
            $path = $root.$relativePath;
            self::assertFileExists($path);
            $contents = (string) file_get_contents($path);

            foreach (self::FORBIDDEN_TOKENS as $token) {
                if (str_contains($contents, $token)) {
                    $offenders[] = \sprintf('%s references "%s"', $relativePath, $token);
                }
            }
        }

        self::assertSame([], $offenders, "AC-9.4/AC-9.5 violation:\n".implode("\n", $offenders));
    }
}
