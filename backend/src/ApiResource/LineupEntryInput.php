<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One entry of `ConcertInput::$lineup` / `ConcertPatchInput::$lineup` (AC-1.3). Array index is
 * billing order — index 0 is the headliner. Exactly one of `$name` (a new-or-existing band, resolved
 * by `App\Service\Concert\BandResolver`) or `$bandId` (an existing band, referenced directly) must
 * be present; enforced by `App\Validator\ValidConcertInputValidator` since it needs the sibling
 * property to check, not by a per-property constraint.
 */
final class LineupEntryInput
{
    /** 1–120 characters after trimming (AC-9.4). Skipped when null (a `bandId` entry). */
    #[Assert\Length(min: 1, max: 120)]
    public ?string $name = null;

    #[Assert\Positive]
    public ?int $bandId = null;
}
