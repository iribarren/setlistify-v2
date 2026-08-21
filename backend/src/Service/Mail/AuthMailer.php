<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * The only place that builds and sends an auth-related email. Depends on `symfony/mailer` and
 * `MAILER_DSN` only — no provider SDK (D-20). In `test`, the DSN is the in-memory transport, so
 * AC-12.2's assertions run against Symfony's mailer test tools with no real service involved.
 *
 * Token values are interpolated into the link only — never logged (AC-11.2 covers the log side via
 * `App\Service\Logging\SensitiveDataProcessor`; this class simply never calls a logger with one).
 */
final readonly class AuthMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private string $mailFromAddress,
        private string $webAppUrl,
        private string $verificationTokenTtl,
        private string $resetTokenTtl,
    ) {
    }

    public function sendEmailVerification(User $user, string $plaintextToken): void
    {
        $link = rtrim($this->webAppUrl, '/').'/verify-email?token='.urlencode($plaintextToken);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromAddress, 'Setlistify'))
            ->to($user->getEmail())
            ->subject('Verify your Setlistify email address')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->context([
                'link' => $link,
                'ttlHours' => (int) ((int) $this->verificationTokenTtl / 3600),
            ]);

        $this->mailer->send($email);
    }

    public function sendPasswordReset(User $user, string $plaintextToken): void
    {
        $link = rtrim($this->webAppUrl, '/').'/reset-password?token='.urlencode($plaintextToken);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromAddress, 'Setlistify'))
            ->to($user->getEmail())
            ->subject('Reset your Setlistify password')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context([
                'link' => $link,
                'ttlMinutes' => (int) ((int) $this->resetTokenTtl / 60),
            ]);

        $this->mailer->send($email);
    }
}
