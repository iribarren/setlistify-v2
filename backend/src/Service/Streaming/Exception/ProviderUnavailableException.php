<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/**
 * The catch-all (AC-10.5): a 5xx, a connection failure, or anything an adapter's error mapper
 * cannot classify into one of the other five taxonomy values. An unclassified failure never escapes
 * an adapter as a raw exception — it becomes this instead.
 */
final class ProviderUnavailableException extends StreamingException
{
}
