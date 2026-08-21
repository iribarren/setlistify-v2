<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\RegisterUserInput;
use App\ApiResource\UserRegistration;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Security\EmailNormalizer;
use App\Service\Security\EmailVerificationService;
use App\Service\Security\RateLimiterGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * `POST /api/users` (US-1, US-10). Assigns `["ROLE_USER"]` unconditionally — {@see RegisterUserInput}
 * has no `roles` property, so there is nothing here to filter (AC-10.1, AC-10.3).
 *
 * @implements ProcessorInterface<RegisterUserInput, UserRegistration>
 */
final readonly class RegisterUserProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailNormalizer $emailNormalizer,
        private EmailVerificationService $emailVerificationService,
        private LoggerInterface $logger,
        private RateLimiterGuard $rateLimiterGuard,
        #[Autowire(service: 'limiter.registration_ip')]
        private RateLimiterFactory $registrationIpLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserRegistration
    {
        /** @var Request $request */
        $request = $context['request'];
        $this->rateLimiterGuard->consume($this->registrationIpLimiter, $request->getClientIp() ?? 'unknown');

        $email = $this->emailNormalizer->normalize($data->email);

        // The validator's UniqueEmail constraint already checked this; this is the race-safety net
        // (AC-1.3) for two simultaneous registrations of the same address.
        $user = new User($email, 'placeholder');
        $user->setPassword($this->passwordHasher->hashPassword($user, $data->password));

        try {
            $this->userRepository->save($user);
        } catch (UniqueConstraintViolationException) {
            throw new UnprocessableEntityHttpException('This email cannot be used.');
        }

        try {
            $this->emailVerificationService->sendVerificationEmail($user);
        } catch (\Throwable $e) {
            // AC-1.8: dispatch failure never fails registration — logged, resend is available.
            $this->logger->error('Failed to dispatch verification email', [
                'user_id' => $user->getId(),
                'exception' => $e::class,
            ]);
        }

        return new UserRegistration(
            id: $user->getSub(),
            email: $user->getEmail(),
            emailVerified: $user->isEmailVerified(),
            createdAt: $user->getCreatedAt(),
        );
    }
}
