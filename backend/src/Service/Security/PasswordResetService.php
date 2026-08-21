<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\RefreshTokenRepository;
use App\Service\Mail\AuthMailer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Issues and consumes single-use, 60-minute password reset tokens (AC-6.2–AC-6.6). On a successful
 * reset, every other outstanding reset token for the user is invalidated and every refresh-token
 * family is revoked — a password reset logs out every device (AC-6.4).
 */
final readonly class PasswordResetService
{
    public function __construct(
        private PasswordResetTokenRepository $repository,
        private RefreshTokenRepository $refreshTokenRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private AuthMailer $mailer,
        private string $tokenTtl,
    ) {
    }

    public function requestReset(User $user): void
    {
        $plaintext = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval("PT{$this->tokenTtl}S"));

        $token = new PasswordResetToken($user, $this->hash($plaintext), $expiresAt);
        $this->repository->save($token);

        $this->mailer->sendPasswordReset($user, $plaintext);
    }

    /** @return bool true if the password was actually changed. */
    public function confirm(string $plaintext, string $newPassword): bool
    {
        $token = $this->repository->findOneByTokenHash($this->hash($plaintext));
        $now = new \DateTimeImmutable();

        if (null === $token || !$token->isValid($now)) {
            return false;
        }

        $user = $token->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));

        $token->markUsed($now);
        $this->repository->save($token);

        $this->repository->invalidateAllForUser($user, $now);
        $this->refreshTokenRepository->revokeAllForUser($user, $now);

        return true;
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
