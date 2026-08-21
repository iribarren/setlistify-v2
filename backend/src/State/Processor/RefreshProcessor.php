<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\RefreshInput;
use App\ApiResource\RefreshOutput;
use App\EventSubscriber\PendingCookieSubscriber;
use App\Service\Security\ClientPlatform;
use App\Service\Security\RateLimiterGuard;
use App\Service\Security\RefreshCookieFactory;
use App\Service\Security\RefreshTokenInvalidException;
use App\Service\Security\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * `POST /api/token/refresh` (US-4, AC-4.1–AC-4.8). Reads the presented token from the httpOnly
 * cookie first (web, D-18), falling back to the request body (native, AC-4.6).
 *
 * @implements ProcessorInterface<RefreshInput, RefreshOutput>
 */
final readonly class RefreshProcessor implements ProcessorInterface
{
    public function __construct(
        private RefreshTokenService $refreshTokenService,
        private RefreshCookieFactory $refreshCookieFactory,
        private JWTTokenManagerInterface $jwtManager,
        private ClientPlatform $clientPlatform,
        private RateLimiterGuard $rateLimiterGuard,
        private string $jwtTtl,
        #[Autowire(service: 'limiter.refresh_ip')]
        private RateLimiterFactory $refreshIpLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RefreshOutput
    {
        /** @var Request $request */
        $request = $context['request'];

        $this->rateLimiterGuard->consume($this->refreshIpLimiter, $request->getClientIp() ?? 'unknown');

        $plaintext = $request->cookies->get(RefreshCookieFactory::COOKIE_NAME) ?? $data->refreshToken;

        if (null === $plaintext || '' === $plaintext) {
            // AC-4.8: no distinction between "missing", "unknown" and "expired".
            throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
        }

        try {
            $issued = $this->refreshTokenService->rotate($plaintext);
        } catch (RefreshTokenInvalidException) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid refresh token.');
        }

        $accessToken = $this->jwtManager->create($issued->entity->getUser());
        $isNative = $this->clientPlatform->isNative($request);

        if ($isNative) {
            $refreshTokenForBody = $issued->plaintext;
        } else {
            $refreshTokenForBody = null;
            PendingCookieSubscriber::queue($request, $this->refreshCookieFactory->create($issued->plaintext));
        }

        return new RefreshOutput(
            accessToken: $accessToken,
            tokenType: 'Bearer',
            expiresIn: (int) $this->jwtTtl,
            refreshToken: $refreshTokenForBody,
        );
    }
}
