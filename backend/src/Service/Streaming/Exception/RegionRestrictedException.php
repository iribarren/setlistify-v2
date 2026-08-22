<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/** The provider refused the request because of the account's or track's market/region. */
final class RegionRestrictedException extends StreamingException
{
}
