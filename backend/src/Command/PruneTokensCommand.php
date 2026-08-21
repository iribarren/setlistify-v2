<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EmailVerificationTokenRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\RefreshTokenRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes expired refresh/reset/verification rows so the token tables don't grow without bound
 * (R-10). Intended to run on a schedule in production (documented in `docs/architecture.md` §9 /
 * the deployment docs) — not wired to a cron here, since this repo has no scheduler yet.
 */
#[AsCommand(
    name: 'app:tokens:prune',
    description: 'Deletes expired refresh, password-reset and email-verification tokens.',
)]
final class PruneTokensCommand extends Command
{
    public function __construct(
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly PasswordResetTokenRepository $passwordResetTokenRepository,
        private readonly EmailVerificationTokenRepository $emailVerificationTokenRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $refresh = $this->refreshTokenRepository->deleteExpiredBefore($now);
        $reset = $this->passwordResetTokenRepository->deleteExpiredBefore($now);
        $verification = $this->emailVerificationTokenRepository->deleteExpiredBefore($now);

        $io->success(\sprintf(
            'Pruned %d refresh, %d password-reset, %d email-verification tokens.',
            $refresh,
            $reset,
            $verification,
        ));

        return Command::SUCCESS;
    }
}
