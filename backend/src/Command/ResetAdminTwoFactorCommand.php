<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Security\EmailNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AC-5.6/D-49: the **only** recovery path for a lost authenticator device or lost backup codes —
 * deliberately console-only, the same bar as `app:admin:create`. There is no web-based "forgot my
 * 2FA" flow; adding one would be a second path to full admin access, exactly what US-2 exists to
 * prevent.
 *
 * Clears both the TOTP secret and every backup code. The account is left in the same state as a
 * freshly-created admin — no TOTP secret means only the enrollment route is reachable
 * (`App\EventSubscriber\ForceTwoFactorEnrollmentSubscriber`) — so the next login re-enrolls with a
 * brand new secret and a brand new set of ten backup codes.
 */
#[AsCommand(
    name: 'app:admin:2fa:reset',
    description: 'Clears an admin account\'s TOTP secret and backup codes, forcing re-enrollment on next login. Console-only 2FA recovery (D-49).',
)]
final class ResetAdminTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailNormalizer $emailNormalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The admin account email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $emailArgument = $input->getArgument('email');
        \assert(\is_string($emailArgument));
        $email = $this->emailNormalizer->normalize($emailArgument);

        $user = $this->userRepository->findOneByEmail($email);
        if (null === $user || !\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $io->error(\sprintf('No admin account for "%s".', $email));

            return Command::FAILURE;
        }

        $user->setTotpSecretCipher(null);
        $user->setBackupCodesHashed([]);
        $this->userRepository->save($user);

        $io->success(\sprintf(
            '2FA cleared for %s. The next login will require enrolling a new authenticator app and will issue a new set of backup codes.',
            $email,
        ));

        return Command::SUCCESS;
    }
}
