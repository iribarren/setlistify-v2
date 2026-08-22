<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * AC-6.5: a ciphertext's key id is neither the active key nor in the retired set. Fails loudly —
 * this is what stops a lost/misconfigured key from looking like "no token was ever stored".
 */
final class UnknownEncryptionKeyException extends \RuntimeException
{
    public function __construct(string $keyId)
    {
        parent::__construct(\sprintf('No encryption key configured for key id "%s" (active or retired).', $keyId));
    }
}
