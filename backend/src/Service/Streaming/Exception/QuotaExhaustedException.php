<?php

declare(strict_types=1);

namespace App\Service\Streaming\Exception;

/**
 * A daily/period quota (units/day for some providers — see `docs/external-apis.md`) is spent, as
 * opposed to a rolling per-second rate limit. Distinct from `RateLimitedException` because the
 * caller's response differs: a rate limit is worth retrying shortly, a quota is not worth retrying
 * until the window resets.
 */
final class QuotaExhaustedException extends StreamingException
{
}
