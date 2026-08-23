<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * `POST /api/playlist-generation-jobs` (spec 14 §6). `provider` is optional — omitted, the default
 * from `ProviderRegistry::all()` is used, restricted to providers the user has a `connected`
 * `StreamingAccount` for (AC-1.2). A hardcoded provider key appears nowhere.
 */
final class StartGenerationInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $concertId = null;

    public ?string $provider = null;
}
