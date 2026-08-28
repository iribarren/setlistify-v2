<?php

declare(strict_types=1);

namespace App\ApiResource\Setlist;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * `POST …/bands/{id}/setlist-refresh/resolution` (docs/specs/2026-08-27-instant-setlist-refresh.md,
 * D-278, AC-6.5). A *selection*, not a value (D-271) — `$selectedMbid` is validated against the
 * candidate list stored on the band's current refresh record, never accepted as a free-text MBID.
 */
final class ResolveBandIdentityInput
{
    #[Assert\NotBlank]
    public ?string $selectedMbid = null;
}
