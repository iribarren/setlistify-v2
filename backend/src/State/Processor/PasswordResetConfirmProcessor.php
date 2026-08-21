<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PasswordResetConfirmInput;
use App\Service\Security\PasswordResetService;
use App\Service\Security\RateLimiterGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * `POST /api/password-reset/confirm` (AC-6.3–AC-6.5, AC-6.7). An expired, unknown or already-used
 * token all produce the same 400 — {@see PasswordResetService::confirm()} returns a plain bool
 * precisely so this processor cannot accidentally leak which case occurred.
 *
 * @implements ProcessorInterface<PasswordResetConfirmInput, void>
 */
final readonly class PasswordResetConfirmProcessor implements ProcessorInterface
{
    public function __construct(
        private PasswordResetService $passwordResetService,
        private RateLimiterGuard $rateLimiterGuard,
        #[Autowire(service: 'limiter.password_reset_confirm_ip')]
        private RateLimiterFactory $confirmIpLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        /** @var Request $request */
        $request = $context['request'];
        $this->rateLimiterGuard->consume($this->confirmIpLimiter, $request->getClientIp() ?? 'unknown');

        if (!$this->passwordResetService->confirm($data->token, $data->password)) {
            throw new BadRequestHttpException('This reset link is invalid or has expired.');
        }
    }
}
