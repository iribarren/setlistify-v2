<?php

declare(strict_types=1);

namespace App\Security\Admin;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * D-50: admin login errors are honest, not the API's uniform-401 posture — there is exactly one
 * admin account, and an operator locked out at 3am needs to know why. Rejects the login attempt
 * *before* the password is even checked, so a lockout doesn't also cost the account another failed
 * attempt.
 */
final readonly class AdminUserChecker implements UserCheckerInterface
{
    public function __construct(
        private AdminLockoutTracker $lockoutTracker,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof AdminUser) {
            return;
        }

        $email = $user->getUserIdentifier();
        if ($this->lockoutTracker->isLocked($email)) {
            $minutes = (int) ceil($this->lockoutTracker->remainingLockSeconds($email) / 60);
            throw new CustomUserMessageAccountStatusException(\sprintf(
                'Too many failed attempts. This account is locked for %d more minute(s).',
                max(1, $minutes),
            ));
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
