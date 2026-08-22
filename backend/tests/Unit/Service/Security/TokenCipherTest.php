<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Security\MalformedTokenEnvelopeException;
use App\Service\Security\TokenCipher;
use App\Service\Security\UnknownEncryptionKeyException;
use PHPUnit\Framework\TestCase;

/**
 * D-78, US-6. `App\Tests\Unit\Doctrine\Type\EncryptedStringTypeTest` covers the same behaviour
 * through the Doctrine type; this test covers the crypto primitive directly, including the
 * decrypt-only-key-id error path AC-6.5 wants proven as a "fails loudly" case.
 */
final class TokenCipherTest extends TestCase
{
    private function key(int $seed): string
    {
        return base64_encode(str_repeat(\chr($seed), \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    public function testEncryptThenDecryptRoundTrips(): void
    {
        $cipher = new TokenCipher(activeKeyId: 'v1', activeKey: base64_decode($this->key(1), true));

        $envelope = $cipher->encrypt('super-secret-access-token');

        self::assertStringStartsWith('v1:v1:', $envelope);
        self::assertSame('super-secret-access-token', $cipher->decrypt($envelope));
    }

    public function testCiphertextIsNotThePlaintextOrAnObviousEncodingOfIt(): void
    {
        $cipher = new TokenCipher(activeKeyId: 'v1', activeKey: base64_decode($this->key(1), true));
        $plaintext = 'BQD1abcSpotifyAccessTokenValue';

        $envelope = $cipher->encrypt($plaintext);

        self::assertStringNotContainsString($plaintext, $envelope);
        self::assertStringNotContainsString(base64_encode($plaintext), $envelope);
    }

    public function testRecordWrittenUnderARetiredKeyStillDecryptsAfterRotation(): void
    {
        $oldKeyRaw = base64_decode($this->key(2), true);
        \assert(\is_string($oldKeyRaw));
        $newKeyRaw = base64_decode($this->key(3), true);
        \assert(\is_string($newKeyRaw));

        // Written before rotation, under the then-active key "old".
        $beforeRotation = new TokenCipher(activeKeyId: 'old', activeKey: $oldKeyRaw);
        $envelopeFromOldKey = $beforeRotation->encrypt('token-written-before-rotation');

        // After rotation: "old" is retired, "new" is active.
        $afterRotation = new TokenCipher(activeKeyId: 'new', activeKey: $newKeyRaw, retiredKeys: ['old' => $oldKeyRaw]);

        // AC-6.4: a record written under the old key still decrypts...
        self::assertSame('token-written-before-rotation', $afterRotation->decrypt($envelopeFromOldKey));

        // ...and a record written after rotation uses the new key.
        $envelopeFromNewKey = $afterRotation->encrypt('token-written-after-rotation');
        self::assertStringStartsWith('v1:new:', $envelopeFromNewKey);
        self::assertSame('token-written-after-rotation', $afterRotation->decrypt($envelopeFromNewKey));
    }

    public function testDecryptingWithAnUnknownKeyIdFailsLoudly(): void
    {
        $writer = new TokenCipher(activeKeyId: 'lost-key', activeKey: base64_decode($this->key(4), true));
        $envelope = $writer->encrypt('token');

        // No retired key holds "lost-key" — AC-6.5: never a silent null, never a plaintext fallback.
        $reader = new TokenCipher(activeKeyId: 'current', activeKey: base64_decode($this->key(5), true));

        $this->expectException(UnknownEncryptionKeyException::class);
        $reader->decrypt($envelope);
    }

    public function testMalformedEnvelopeFailsLoudly(): void
    {
        $cipher = new TokenCipher(activeKeyId: 'v1', activeKey: base64_decode($this->key(1), true));

        $this->expectException(MalformedTokenEnvelopeException::class);
        $cipher->decrypt('not-a-valid-envelope');
    }

    public function testConstructorRejectsAWrongSizedKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenCipher(activeKeyId: 'v1', activeKey: 'too-short');
    }
}
