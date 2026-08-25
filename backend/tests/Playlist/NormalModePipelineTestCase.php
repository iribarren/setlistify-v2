<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\Concert;
use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Service\Playlist\Choice\SetlistChoiceApplier;
use App\Service\Playlist\Choice\VersionChoiceApplier;
use App\Service\Playlist\Model\JobMode;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;

/**
 * Shared scaffolding for the Normal-mode test suite (docs/specs/2026-08-25-playlist-normal-mode.md),
 * on top of `PlaylistPipelineTestCase`'s truncation/fixture shape. Adds `user_track_preferences` to
 * the truncated tables — the one Normal-mode-owned table `PlaylistPipelineTestCase` doesn't know
 * about — and a `JobMode::Normal` job constructor.
 */
abstract class NormalModePipelineTestCase extends PlaylistPipelineTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager()->getConnection()->executeStatement('TRUNCATE user_track_preferences RESTART IDENTITY CASCADE');
        $this->entityManager()->clear();
    }

    protected function newNormalJob(User $user, Concert $concert, StreamingAccount $account, \DateTimeImmutable $now, string $seed = 'a'): PlaylistGenerationJob
    {
        return new PlaylistGenerationJob($user, $concert, TestDoubleStreamingProvider::KEY, $account, JobMode::Normal, str_repeat($seed, 64), 1, $now);
    }

    protected function setlistChoiceApplier(): SetlistChoiceApplier
    {
        return self::getContainer()->get(SetlistChoiceApplier::class);
    }

    protected function versionChoiceApplier(): VersionChoiceApplier
    {
        return self::getContainer()->get(VersionChoiceApplier::class);
    }
}
