<?php

declare(strict_types=1);

namespace App\ApiResource\Playlist;

use App\Service\Playlist\Model\JobMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `POST /api/playlist-generation-jobs` (spec 14 §6, extended by docs/specs/
 * 2026-08-25-playlist-normal-mode.md D-190/AC-1.1/AC-4.3). `provider` is optional — omitted, the
 * default from `ProviderRegistry::all()` is used, restricted to providers the user has a
 * `connected` `StreamingAccount` for (AC-1.2). A hardcoded provider key appears nowhere.
 *
 * `mode` is optional and defaults to Fast mode when omitted, preserving every existing caller's
 * behaviour unchanged. `resumeFromJobId` is the mechanism behind AC-4.3: the client's primary
 * action on an `expired` view names the job it is resuming from, so the new job can pre-fill the
 * setlist choice the old one already made.
 */
final class StartGenerationInput
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $concertId = null;

    public ?string $provider = null;

    /** @var 'fast'|'normal'|null */
    #[Assert\Choice(choices: [JobMode::Fast->value, JobMode::Normal->value])]
    public ?string $mode = null;

    /** AC-4.3: only meaningful against an `expired` job — enforced in the processor (422 otherwise). */
    #[Assert\Positive]
    public ?int $resumeFromJobId = null;
}
