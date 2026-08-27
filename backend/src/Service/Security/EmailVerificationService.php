<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Repository\EmailVerificationTokenRepository;
use App\Service\Mail\AuthMailer;

/**
 * Issues and consumes single-use, 24-hour email verification tokens (AC-7.1, AC-7.2). Stored
 * hashed, exactly like {@see PasswordResetService} — the plaintext exists only in the email.
 */
final readonly class EmailVerificationService
{
    public function __construct(
        private EmailVerificationTokenRepository $repository,
        private AuthMailer $mailer,
        private string $tokenTtl,
    ) {
    }

    public function sendVerificationEmail(User $user): void
    {
        $plaintext = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval("PT{$this->tokenTtl}S"));

        $token = new EmailVerificationToken($user, $this->hash($plaintext), $expiresAt);
        $this->repository->save($token);

        $this->mailer->sendEmailVerification($user, $plaintext);
    }

    /**
     * @return bool true if a valid, unexpired, unused token was found and consumed —
     *              {@see EmailVerificationConfirmProcessor} throws its generic 400 on `false`. This
     *              is deliberately "was the token valid", not "did verification just happen": D-252
     *              guards against a stale token silently overwriting an admin-set
     *              {@see User::markEmailVerified()} timestamp (see below), but the HTTP response for
     *              a structurally valid token must stay a 204 either way — a valid token consumed
     *              against an already-verified user is a successful no-op, not a client error.
     */
    public function confirm(string $plaintext): bool
    {
        $token = $this->repository->findOneByTokenHash($this->hash($plaintext));
        $now = new \DateTimeImmutable();

        if (null === $token || !$token->isValid($now)) {
            return false;
        }

        $token->markUsed($now);

        // D-252: a token consumed after the admin has already manually verified this user (see
        // UserCrudController::performVerifyEmail()) must not silently overwrite the operator's
        // timestamp, or it would disagree with the audit entry the admin action wrote.
        $user = $token->getUser();
        if (!$user->isEmailVerified()) {
            $user->markEmailVerified($now);
        }

        $this->repository->save($token);

        return true;
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
