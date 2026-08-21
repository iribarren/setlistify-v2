<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\EmailVerificationConfirmInput;
use App\Service\Security\EmailVerificationService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `POST /api/email-verification/confirm` (AC-7.2). A used, expired or unknown token all produce
 * the same 400.
 *
 * @implements ProcessorInterface<EmailVerificationConfirmInput, void>
 */
final readonly class EmailVerificationConfirmProcessor implements ProcessorInterface
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$this->emailVerificationService->confirm($data->token)) {
            throw new BadRequestHttpException('This verification link is invalid or has expired.');
        }
    }
}
