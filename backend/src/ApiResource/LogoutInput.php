<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `POST /api/logout`'s body. Structurally identical to {@see RefreshInput}, but kept as its own
 * class: API Platform resolves the operation/processor to run partly by input class, and two
 * different resources (`LogoutAction`, `RefreshOutput`) sharing one input class was observed to
 * misroute requests between their processors during manual smoke testing. One input class per
 * resource avoids that entirely.
 */
final class LogoutInput
{
    public ?string $refreshToken = null;
}
