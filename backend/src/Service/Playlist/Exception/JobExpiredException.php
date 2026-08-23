<?php

declare(strict_types=1);

namespace App\Service\Playlist\Exception;

/** T-17: a suspended job was found past its TTL by `app:playlist:expire-jobs`. */
final class JobExpiredException extends \RuntimeException
{
}
