<?php

declare(strict_types=1);

namespace App\Security\Admin;

/**
 * Encrypts/decrypts the admin's TOTP secret at rest (AC-5.3), using the same
 * libsodium `xchacha20poly1305` scheme `docs/env-vars.md` already documents for provider OAuth
 * tokens — a nonce per ciphertext, base64-encoded for storage in a `text` column.
 *
 * Deliberately a plain class, not a value read inside `App\Entity\User` — entities never depend on
 * services (`docs/architecture.md` layering). Only `App\Security\Admin\AdminUser` (constructed by
 * `AdminUserProvider`, which has DI) ever calls this.
 */
final readonly class TotpSecretEncryptor
{
    public function __construct(
        private string $base64Key,
    ) {
    }

    public function encrypt(string $plaintextSecret): string
    {
        $key = $this->key();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintextSecret, $nonce, $key);

        return base64_encode($nonce.$ciphertext);
    }

    public function decrypt(string $cipherBase64): string
    {
        $key = $this->key();
        $raw = base64_decode($cipherBase64, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed TOTP secret ciphertext.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if (false === $plaintext) {
            throw new \RuntimeException('Failed to decrypt TOTP secret — wrong key or corrupted ciphertext.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $key = base64_decode($this->base64Key, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \RuntimeException('ADMIN_TOTP_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        return $key;
    }
}
