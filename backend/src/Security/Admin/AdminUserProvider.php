<?php

declare(strict_types=1);

namespace App\Security\Admin;

use App\Repository\UserRepository;
use App\Service\Admin\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads by email and requires `ROLE_ADMIN` — a `ROLE_USER`-only account simply doesn't exist for
 * this firewall (`UserNotFoundException`, the same response Symfony gives for "no such email"), so
 * AC-3.5's "refused and logged" behaviour is a `LoginFailureEvent` like any other failed login (see
 * `App\EventSubscriber\AdminLoginAttemptSubscriber`).
 *
 * @implements UserProviderInterface<AdminUser>
 */
final readonly class AdminUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private TotpSecretEncryptor $encryptor,
        private PasswordHasherFactoryInterface $passwordHasherFactory,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $securityLogger,
        private RequestStack $requestStack,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneByEmail(strtolower(trim($identifier)));

        if (null !== $user && !\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            // AC-3.5: a real (non-admin) account attempting the admin door is a signal, not noise.
            $request = $this->requestStack->getCurrentRequest();
            $this->securityLogger->warning('Non-admin account attempted admin login', [
                'userId' => $user->getId(),
                'path' => $request?->getPathInfo(),
                'ip' => $request?->getClientIp(),
            ]);
        }

        if (null === $user || !\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            throw new UserNotFoundException(\sprintf('No admin account for "%s".', $identifier));
        }

        return new AdminUser($user, $this->encryptor, $this->passwordHasherFactory, $this->entityManager, $this->auditLogger);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AdminUser) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return AdminUser::class === $class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof AdminUser) {
            return;
        }

        $user->getWrappedUser()->setPassword($newHashedPassword);
        $this->userRepository->save($user->getWrappedUser());
    }
}
