<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ProviderSetting;
use App\Service\Provider\PlaybackMode;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AC-9.1 (docs/specs/2026-08-22-backoffice-provider-configuration.md): `provider_settings`'s
 * columns against a hardcoded expected list. Fails on any addition — deliberately, so the failure
 * message names the credential rule rather than leaving the diff to speak for itself.
 */
final class ProviderSettingSchemaTest extends KernelTestCase
{
    public function testColumnsAreExactlyTheDeclaredAllowlist(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var ClassMetadata<ProviderSetting> $metadata */
        $metadata = $em->getClassMetadata(ProviderSetting::class);

        $fieldNames = $metadata->getFieldNames();
        sort($fieldNames);

        $expected = ['createdAt', 'enabled', 'id', 'isDefault', 'notes', 'playbackMode', 'provider', 'updatedAt'];
        sort($expected);

        self::assertSame(
            $expected,
            $fieldNames,
            "CLAUDE.md: 'the backoffice edits behaviour, never credentials'. ProviderSetting must hold ONLY "
            .'behaviour flags (provider, enabled, playbackMode, isDefault, notes, timestamps) — never a client '
            .'id, secret, or any other credential-shaped column. If this test fails because a column was '
            .'added, stop and re-read D-89/AC-9.1 before proceeding: the fix is almost certainly to move that '
            .'value into the secrets layer, not to update this allowlist.',
        );
    }

    /** AC-2.1: exactly three values — an invalid one is unrepresentable in PHP, not just rejected at runtime. */
    public function testPlaybackModeHasExactlyThreeValues(): void
    {
        $values = array_map(static fn (PlaybackMode $case) => $case->value, PlaybackMode::cases());
        sort($values);

        self::assertSame(['deeplink', 'embed', 'off'], $values);
    }
}
