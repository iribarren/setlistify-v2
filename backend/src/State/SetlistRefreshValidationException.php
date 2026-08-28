<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;

/**
 * The pick's two `422` refusals (docs/specs/2026-08-27-instant-setlist-refresh.md, AC-4.7):
 * `mbid_not_a_candidate` and `band_already_resolved`. Deliberately `422`, not `429` — no amount of
 * waiting makes a non-candidate MBID valid, and a resolved band is finished, not busy. Neither is a
 * member of `refusedReason` (AC-4.7).
 */
final class SetlistRefreshValidationException extends \RuntimeException implements ProblemExceptionInterface
{
    /** @param 'mbid_not_a_candidate'|'band_already_resolved' $reason */
    public function __construct(private readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function getType(): string
    {
        return '/errors/setlist-refresh-validation';
    }

    public function getTitle(): string
    {
        return 'Setlist refresh pick refused';
    }

    public function getStatus(): int
    {
        return 422;
    }

    public function getDetail(): string
    {
        return $this->reason;
    }

    public function getInstance(): string
    {
        return '';
    }
}
