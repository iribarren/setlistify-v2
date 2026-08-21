<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\LogoutInput;
use App\EventSubscriber\PendingCookieSubscriber;
use App\Service\Security\RefreshCookieFactory;
use App\Service\Security\RefreshTokenService;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /api/logout` (AC-5.1–AC-5.4). Never throws: an already-invalid or missing token is treated
 * the same as a valid one — the response is always 204.
 *
 * @implements ProcessorInterface<LogoutInput, void>
 */
final readonly class LogoutProcessor implements ProcessorInterface
{
    public function __construct(
        private RefreshTokenService $refreshTokenService,
        private RefreshCookieFactory $refreshCookieFactory,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        /** @var Request $request */
        $request = $context['request'];
        $plaintext = $request->cookies->get(RefreshCookieFactory::COOKIE_NAME) ?? $data->refreshToken;

        if (null !== $plaintext && '' !== $plaintext) {
            $this->refreshTokenService->revokeFamilyForToken($plaintext);
        }

        PendingCookieSubscriber::queue($request, $this->refreshCookieFactory->clear());
    }
}
