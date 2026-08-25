<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use Symfony\Component\Validator\Constraints as Assert;

/** `providerTrackId: null` means "none of these" — AC-2.6's decline. */
final class VersionChoiceItemInput
{
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    public ?int $sourcePosition = null;

    public ?int $segmentIndex = null;

    public ?string $providerTrackId = null;
}
