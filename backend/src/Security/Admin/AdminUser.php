<?php

declare(strict_types=1);

namespace App\Security\Admin;

use App\Entity\User;
use App\Service\Admin\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\ConstantTime\Base32;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The admin firewall's security model object (D-47) — wraps {@see User} rather than making `User`
 * itself implement scheb/2fa's interfaces, so 2FA stays scoped to this firewall alone: the `api`
 * firewall's provider resolves plain `User` entities, which don't implement
 * {@see TwoFactorInterface}, so scheb never intercepts anything there (AC-5.1).
 *
 * `isTotpAuthenticationEnabled()` is unconditionally `true` for any `ROLE_ADMIN` account — even one
 * with no secret yet — because returning `false` would make scheb skip 2FA entirely for that
 * session (password alone reaching the dashboard). Instead, when there is no secret,
 * `getTotpAuthenticationConfiguration()` returns a configuration built from a throwaway random
 * secret nobody knows, so the TOTP form mechanically works (no exception) but can never succeed —
 * {@see \App\EventSubscriber\ForceTwoFactorEnrollmentSubscriber} is what actually redirects such a
 * session to enrollment (D-49) before it ever reaches that form.
 */
final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    public function __construct(
        private readonly User $user,
        private readonly TotpSecretEncryptor $encryptor,
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * `Symfony\Component\Security\Http\Firewall\ContextListener` serializes the security token
     * (and therefore this object) into the session at the end of *every* admin request — not only
     * during login/2FA-check. The injected services (EntityManager, AuditLogger, password hasher
     * factory) hold live Doctrine metadata/proxy state that PHP's `serialize()` refuses. Only
     * `$user` needs to survive the round trip: the *next* request always reconstructs a fresh,
     * fully-wired `AdminUser` via `AdminUserProvider::refreshUser()` rather than reusing this one.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['user'];
    }

    public function getWrappedUser(): User
    {
        return $this->user;
    }

    public function hasTotpSecret(): bool
    {
        return null !== $this->user->getTotpSecretCipher();
    }

    public function getUserIdentifier(): string
    {
        return $this->user->getUserIdentifier();
    }

    public function getPassword(): string
    {
        return $this->user->getPassword();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->user->getRoles();
    }

    public function eraseCredentials(): void
    {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return \in_array('ROLE_ADMIN', $this->user->getRoles(), true);
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->user->getEmail();
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        $cipher = $this->user->getTotpSecretCipher();
        $secret = null !== $cipher
            ? $this->encryptor->decrypt($cipher)
            // No real secret yet (D-49) — a random, never-persisted, valid-base32 placeholder so
            // the TOTP provider doesn't throw on a malformed secret; ForceTwoFactorEnrollmentSubscriber
            // keeps this form unreachable in practice.
            : Base32::encodeUpper(random_bytes(20));

        return new TotpConfiguration($secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function isBackupCode(string $code): bool
    {
        $hasher = $this->passwordHasherFactory->getPasswordHasher(User::class);

        foreach ($this->user->getBackupCodesHashed() as $hashedCode) {
            if ($hasher->verify($hashedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hasher = $this->passwordHasherFactory->getPasswordHasher(User::class);

        $remaining = array_values(array_filter(
            $this->user->getBackupCodesHashed(),
            static fn (string $hashedCode): bool => !$hasher->verify($hashedCode, $code),
        ));

        $this->user->setBackupCodesHashed($remaining);
        $this->entityManager->flush();

        $this->auditLogger->log(
            actor: $this->user,
            action: 'backup_code_used',
            subjectType: 'User',
            subjectId: $this->user->getId() ?? 0,
        );
    }
}
