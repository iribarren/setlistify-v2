<?php

declare(strict_types=1);

namespace App\Service\Playlist\Exception;

/** F-01: setlist.fm's daily budget is spent and nothing is cached for this band. */
final class SetlistBudgetExhaustedException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?\DateTimeImmutable $budgetResetAt = null)
    {
        parent::__construct($message);
    }
}
