<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\EmailNormalizer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * **The only path to `ROLE_ADMIN`** (AC-10.4, US-10). No public API operation can grant it — see
 * `App\ApiResource\RegisterUserInput`, which has no `roles` field at all — so provisioning the
 * owner/operator account is deliberately a shell-access-only console command, documented in
 * `docs/architecture.md` §9.
 */
#[AsCommand(
    name: 'app:admin:create',
    description: 'Creates (or promotes an existing) user to ROLE_ADMIN. Requires shell access — this is the only path to ROLE_ADMIN.',
)]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailNormalizer $emailNormalizer,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The account email')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password for a new account — ignored when promoting an existing user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $emailArgument = $input->getArgument('email');
        \assert(\is_string($emailArgument));
        $email = $this->emailNormalizer->normalize($emailArgument);

        $password = $input->getArgument('password');
        \assert(null === $password || \is_string($password));

        $user = $this->userRepository->findOneByEmail($email);

        if (null !== $user) {
            $roles = $user->getRoles();
            if (\in_array('ROLE_ADMIN', $roles, true)) {
                $io->success(\sprintf('%s already has ROLE_ADMIN.', $email));

                return Command::SUCCESS;
            }

            $roles[] = 'ROLE_ADMIN';
            $user->setRoles($roles);
            $this->userRepository->save($user);

            $io->success(\sprintf('%s promoted to ROLE_ADMIN.', $email));

            return Command::SUCCESS;
        }

        if (null === $password || '' === $password) {
            $io->error('No user with that email exists — pass a password to create one.');

            return Command::FAILURE;
        }

        $user = new User($email, 'placeholder');
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        try {
            $this->userRepository->save($user);
        } catch (UniqueConstraintViolationException) {
            $io->error('That email was just registered by someone else — try again.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Created %s with ROLE_ADMIN.', $email));

        return Command::SUCCESS;
    }
}
