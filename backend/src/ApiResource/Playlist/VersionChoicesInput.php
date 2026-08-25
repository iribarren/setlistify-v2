<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use Symfony\Component\Validator\Constraints as Assert;

/** `POST /api/playlist-generation-jobs/{id}/version-choices` body (T-08, D-192 — full replacement). */
final class VersionChoicesInput
{
    /** @var list<VersionChoiceItemInput> */
    #[Assert\NotNull]
    #[Assert\Valid]
    public array $choices = [];
}
