<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use Symfony\Component\Validator\Constraints as Assert;

/** `POST /api/playlist-generation-jobs/{id}/setlist-choice` body (T-05). */
final class SetlistChoiceInput
{
    /**
     * @var list<SetlistChoiceItemInput>
     */
    #[Assert\NotNull]
    #[Assert\Valid]
    #[Assert\Count(min: 1)]
    public array $choices = [];
}
