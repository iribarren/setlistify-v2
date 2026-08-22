<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/**
 * Common base of the provider-agnostic error taxonomy (US-10, D-73). Every failure a
 * `StreamingProviderInterface` adapter can produce is one of this class's six subclasses — never a
 * raw HTTP status, never a provider-shaped exception (AC-10.1, AC-10.2). A caller may catch this
 * base type to handle "any streaming failure" generically, or a specific subclass to branch on
 * outcome.
 */
abstract class StreamingException extends \RuntimeException
{
}
