<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GenericAck;
use App\Entity\User;
use App\Service\Security\EmailVerificationService;
use App\Service\Security\RateLimiterGuard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * `POST /api/email-verification/resend` (AC-7.3). Always 202 and never reveals whether the account
 * was already verified — an already-verified user gets the identical acknowledgement, just no
 * email.
 *
 * @implements ProcessorInterface<mixed, GenericAck>
 */
final readonly class EmailVerificationResendProcessor implements ProcessorInterface
{
    private const string ACK_MESSAGE = 'If your email address needs verifying, a new link has been sent.';

    public function __construct(
        private Security $security,
        private EmailVerificationService $emailVerificationService,
        private RateLimiterGuard $rateLimiterGuard,
        #[Autowire(service: 'limiter.verification_resend_user')]
        private RateLimiterFactory $resendLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GenericAck
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $this->rateLimiterGuard->consume($this->resendLimiter, (string) $user->getId());

        if (!$user->isEmailVerified()) {
            $this->emailVerificationService->sendVerificationEmail($user);
        }

        return new GenericAck(self::ACK_MESSAGE);
    }
}
