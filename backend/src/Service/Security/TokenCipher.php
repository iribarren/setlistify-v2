<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * The libsodium `xchacha20poly1305` scheme behind `App\Doctrine\Type\EncryptedStringType` (D-78,
 * US-6) — the same family of encryption `App\Security\Admin\TotpSecretEncryptor` already uses for
 * the admin's TOTP secret, generalised into a reusable, key-rotatable class.
 *
 * The envelope is `v1:<keyId>:<base64(nonce‖ciphertext)>` — versioned so a future scheme change is
 * a detectable parse failure rather than an ambiguous guess (D-78, AC-6.3). `$activeKeyId`/
 * `$activeKey` is the pair every new ciphertext is written under; `$retiredKeys` (keyId => raw key)
 * are decrypt-only predecessors kept around so a record written under a retired key still decrypts
 * after rotation (AC-6.4). An unknown key id fails loudly — {@see UnknownEncryptionKeyException} —
 * never a silent null and never a plaintext fallback (AC-6.5).
 */
final readonly class TokenCipher
{
    private const string ENVELOPE_VERSION = 'v1';

    /** @param array<string, string> $retiredKeys keyId => raw 32-byte key */
    public function __construct(
        private string $activeKeyId,
        private string $activeKey,
        private array $retiredKeys = [],
    ) {
        if (\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($this->activeKey)) {
            throw new \InvalidArgumentException('TOKEN_ENCRYPTION_KEY must decode to a 32-byte libsodium key.');
        }
    }

    /**
     * Builds a cipher straight from the real process environment (`TOKEN_ENCRYPTION_KEY`,
     * `TOKEN_ENCRYPTION_KEY_ID`, `TOKEN_ENCRYPTION_KEYS_RETIRED`) — Doctrine's custom-type
     * mechanism instantiates {@see \App\Doctrine\Type\EncryptedStringType} without dependency
     * injection (a genuine DBAL limitation, not a design choice here), so this is the one place
     * env vars are read directly rather than bound through `config/services.yaml`.
     */
    public static function fromEnvironment(): self
    {
        $activeKeyId = (string) (getenv('TOKEN_ENCRYPTION_KEY_ID') ?: 'v1');
        $activeKeyBase64 = (string) getenv('TOKEN_ENCRYPTION_KEY');
        $retiredCsv = (string) (getenv('TOKEN_ENCRYPTION_KEYS_RETIRED') ?: '');

        return new self(
            activeKeyId: $activeKeyId,
            activeKey: self::decodeKey($activeKeyBase64, 'TOKEN_ENCRYPTION_KEY'),
            retiredKeys: self::parseRetiredKeys($retiredCsv),
        );
    }

    /** @return array<string, string> */
    private static function parseRetiredKeys(string $csv): array
    {
        $retired = [];
        foreach (array_filter(explode(',', $csv), static fn (string $pair): bool => '' !== trim($pair)) as $pair) {
            [$id, $base64Key] = array_pad(explode(':', $pair, 2), 2, '');
            if ('' === $id || '' === $base64Key) {
                continue;
            }
            $retired[$id] = self::decodeKey($base64Key, 'TOKEN_ENCRYPTION_KEYS_RETIRED');
        }

        return $retired;
    }

    private static function decodeKey(string $base64, string $sourceName): string
    {
        $key = base64_decode($base64, true);
        if (false === $key || \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException(\sprintf('%s must be a base64-encoded 32-byte libsodium key.', $sourceName));
        }

        return $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->activeKey);

        return \sprintf('%s:%s:%s', self::ENVELOPE_VERSION, $this->activeKeyId, base64_encode($nonce.$ciphertext));
    }

    public function decrypt(string $envelope): string
    {
        $parts = explode(':', $envelope, 3);
        if (3 !== \count($parts)) {
            throw new MalformedTokenEnvelopeException('Envelope is not in "version:keyId:payload" form.');
        }

        [$version, $keyId, $payloadBase64] = $parts;
        if (self::ENVELOPE_VERSION !== $version) {
            throw new MalformedTokenEnvelopeException(\sprintf('Unsupported envelope version "%s".', $version));
        }

        $key = $this->keyFor($keyId);

        $raw = base64_decode($payloadBase64, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new MalformedTokenEnvelopeException('Envelope payload is not valid base64/too short to contain a nonce.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $key);
        if (false === $plaintext) {
            throw new MalformedTokenEnvelopeException('Decryption failed — wrong key or corrupted ciphertext.');
        }

        return $plaintext;
    }

    private function keyFor(string $keyId): string
    {
        if ($keyId === $this->activeKeyId) {
            return $this->activeKey;
        }

        return $this->retiredKeys[$keyId] ?? throw new UnknownEncryptionKeyException($keyId);
    }
}
