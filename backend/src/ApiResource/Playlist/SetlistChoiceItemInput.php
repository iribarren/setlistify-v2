<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use Symfony\Component\Validator\Constraints as Assert;

final class SetlistChoiceItemInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $bandId = null;

    #[Assert\NotBlank]
    public ?string $setlistfmId = null;
}
