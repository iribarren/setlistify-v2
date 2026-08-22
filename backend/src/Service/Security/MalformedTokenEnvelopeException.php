<?php

declare(strict_types=1);

namespace App\Service\Security;

/** The ciphertext envelope is not `v1:<keyId>:<payload>`, or fails to decrypt under its key. */
final class MalformedTokenEnvelopeException extends \RuntimeException
{
}
