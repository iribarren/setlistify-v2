<?php

declare(strict_types=1);

namespace App\Tests\Playlist;

use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\JobStateMachine;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\JobState;

/**
 * AC-7/AC-10, T-INT-14 (spec 14 §8, F-06): an OAuth token that expires mid-run — not caught at
 * preflight, which only checks `StreamingAccount::$status`, but discovered by
 * `StreamingTokenManager::usableTokens()` when it tries to refresh a token close to expiry and the
 * provider's refresh grant is itself dead. The account is already `needs_reauth` by the time the
 * pipeline sees `TokenExpiredException` (D-80) — the job simply blocks on top of that fact.
 */
final class PlaylistPipelineTokenExpiryTest extends PlaylistPipelineTestCase
{
    public function testTokenExpiringMidRunBlocksNeedsReauthWithNoResumableAfter(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->entityManager();

        $user = $this->newUser('token-expiry');
        $em->persist($user);
        $band = $this->newBand('Token Expiry Testers', $now);
        $em->persist($band);
        $concert = $this->newConcert($user, $now);
        $concert->addLineupEntry($band, 0);
        $em->persist($concert);
        $this->newCachedSetlist($band, new \DateTimeImmutable('2023-05-01'), ['Song One', 'Song Two'], $now);

        // Connected at preflight (status is untouched), but already past expiry — usableTokens()
        // will try to refresh once it is asked for a token during matching.
        $account = new StreamingAccount($user, \App\Tests\Support\Streaming\TestDoubleStreamingProvider::KEY, 'token', 'refresh-token', $now->modify('-1 minute'), [], 'acct-'.uniqid('', true), null, $now);
        $em->persist($account);
        $em->flush();
        self::assertSame(StreamingAccount::STATUS_CONNECTED, $account->getStatus());

        $job = new PlaylistGenerationJob($user, $concert, \App\Tests\Support\Streaming\TestDoubleStreamingProvider::KEY, $account, \App\Service\Playlist\Model\JobMode::Fast, str_repeat('t', 64), 1, $now);
        $this->jobRepository()->save($job);

        $this->testDoubleProvider()->scriptRefreshTokenExpires();

        try {
            $this->pipeline()->run($job);
            self::fail('Expected GenerationBlockedException.');
        } catch (GenerationBlockedException $e) {
            self::assertSame(BlockedReason::NeedsReauth, $e->reason);
            self::assertNull($e->resumableAfter, 'F-06: resumableAfter is null — a human must re-link, no sweeper can do it automatically.');
            self::getContainer()->get(JobStateMachine::class)->block($job, $e->reason, $e->resumableAfter, $e->stage);
        }

        $this->entityManager()->clear();
        $reloadedAccount = self::getContainer()->get(\App\Repository\StreamingAccountRepository::class)->find($account->getId());
        self::assertInstanceOf(StreamingAccount::class, $reloadedAccount);
        self::assertSame(StreamingAccount::STATUS_NEEDS_REAUTH, $reloadedAccount->getStatus(), 'D-80: the account is already flipped by the time the pipeline sees TokenExpiredException.');

        $reloadedJob = $this->jobRepository()->find($job->getId());
        self::assertInstanceOf(PlaylistGenerationJob::class, $reloadedJob);
        self::assertSame(JobState::Blocked, $reloadedJob->getState());
        self::assertSame(BlockedReason::NeedsReauth, $reloadedJob->getBlockedReason());
    }
}
