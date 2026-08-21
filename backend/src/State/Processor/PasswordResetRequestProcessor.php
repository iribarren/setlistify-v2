<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GenericAck;
use App\ApiResource\PasswordResetRequestInput;
use App\Repository\UserRepository;
use App\Service\Security\EmailNormalizer;
use App\Service\Security\PasswordResetService;
use App\Service\Security\RateLimiterGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * `POST /api/password-reset/request` (AC-6.1, AC-6.6, US-9). Always returns the same acknowledgement
 * whether or not the email exists — the branch below never reaches the response, only the mailer.
 *
 * @implements ProcessorInterface<PasswordResetRequestInput, GenericAck>
 */
final readonly class PasswordResetRequestProcessor implements ProcessorInterface
{
    private const string ACK_MESSAGE = 'If that email address has an account, a password reset link has been sent.';

    public function __construct(
        private UserRepository $userRepository,
        private EmailNormalizer $emailNormalizer,
        private PasswordResetService $passwordResetService,
        private RateLimiterGuard $rateLimiterGuard,
        #[Autowire(service: 'limiter.password_reset_request_ip')]
        private RateLimiterFactory $requestIpLimiter,
        #[Autowire(service: 'limiter.password_reset_request_email')]
        private RateLimiterFactory $requestEmailLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GenericAck
    {
        /** @var Request $request */
        $request = $context['request'];
        $email = $this->emailNormalizer->normalize($data->email);

        $this->rateLimiterGuard->consume($this->requestIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimiterGuard->consume($this->requestEmailLimiter, $email);

        $user = $this->userRepository->findOneByEmail($email);
        if (null !== $user) {
            $this->passwordResetService->requestReset($user);
        }

        return new GenericAck(self::ACK_MESSAGE);
    }
}
