<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/** The provider says the resource (track, playlist, account) does not exist. */
final class NotFoundException extends StreamingException
{
}
