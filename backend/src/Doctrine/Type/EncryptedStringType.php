<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Service\Security\TokenCipher;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * D-78/US-6: encryption as a Doctrine type, not a service call — persisting a `StreamingAccount`'s
 * `accessToken`/`refreshToken` through this type is the ONLY way those columns are ever written, so
 * "forgot to encrypt" is not a reachable mistake. Registered as `encrypted_string`
 * (`config/packages/doctrine.yaml`); the raw database column is `text` (ciphertext is always
 * larger than and unrelated in shape to the plaintext).
 *
 * Doctrine's `Type` instances are singletons instantiated by `Type::getType()` with no constructor
 * arguments — a genuine DBAL limitation, not a design choice — so this class cannot receive
 * `TokenCipher` through normal dependency injection. It lazily builds one from the real process
 * environment on first use (`TokenCipher::fromEnvironment()`), matching this app's D-5 convention
 * of reading configuration from real env vars rather than a parsed `.env` file. Tests that need a
 * specific cipher (key rotation, AC-6.4) call {@see self::configure()} to override it explicitly;
 * {@see self::reset()} clears that override back to the environment-derived default.
 */
final class EncryptedStringType extends Type
{
    public const string NAME = 'encrypted_string';

    private static ?TokenCipher $cipherOverride = null;

    public static function configure(TokenCipher $cipher): void
    {
        self::$cipherOverride = $cipher;
    }

    public static function reset(): void
    {
        self::$cipherOverride = null;
    }

    private static function cipher(): TokenCipher
    {
        return self::$cipherOverride ?? TokenCipher::fromEnvironment();
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('encrypted_string expects a string value.');
        }

        return self::cipher()->encrypt($value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return self::cipher()->decrypt((string) $value);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
