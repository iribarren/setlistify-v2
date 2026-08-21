<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\LoginInput;
use App\ApiResource\LoginOutput;
use App\Entity\User;
use App\EventSubscriber\PendingCookieSubscriber;
use App\Repository\UserRepository;
use App\Security\Voter\EmailVerifiedVoter;
use App\Service\Security\ClientPlatform;
use App\Service\Security\EmailNormalizer;
use App\Service\Security\RateLimiterGuard;
use App\Service\Security\RefreshCookieFactory;
use App\Service\Security\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * `POST /api/login` (US-2). Deliberately not a Symfony `json_login` firewall listener: doing this
 * as a plain state processor keeps rate limiting, the dummy-hash timing guard (AC-9.4) and the
 * cookie/body transport split (D-18) in one place, in the same style as every other auth endpoint.
 *
 * @implements ProcessorInterface<LoginInput, LoginOutput>
 */
final readonly class LoginProcessor implements ProcessorInterface
{
    /**
     * A precomputed hash burned into a throwaway user so an unknown-email login still pays the
     * password-verification cost (AC-9.4) — without this, "unknown email" responds measurably
     * faster than "wrong password", which is exactly the timing oracle US-9 exists to close.
     */
    private const string DUMMY_HASH = '$2y$13$C6UzMDM.H6dfI/f/IKcEeO0rQxJHrYh6qgxKqjTGZ2rP4hMV6l6Bq';

    public function __construct(
        private UserRepository $userRepository,
        private EmailNormalizer $emailNormalizer,
        private UserPasswordHasherInterface $passwordHasher,
        private AuthorizationCheckerInterface $authorizationChecker,
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenService $refreshTokenService,
        private RefreshCookieFactory $refreshCookieFactory,
        private ClientPlatform $clientPlatform,
        private RateLimiterGuard $rateLimiterGuard,
        private string $jwtTtl,
        #[Autowire(service: 'limiter.login_ip')]
        private RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.login_credentials')]
        private RateLimiterFactory $loginCredentialsLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LoginOutput
    {
        /** @var Request $request */
        $request = $context['request'];
        $email = $this->emailNormalizer->normalize($data->email);

        $this->rateLimiterGuard->consume($this->loginIpLimiter, $request->getClientIp() ?? 'unknown');
        $this->rateLimiterGuard->consume($this->loginCredentialsLimiter, $request->getClientIp().'|'.$email);

        $user = $this->userRepository->findOneByEmail($email);

        if (null === $user) {
            // AC-9.4: burn the same time an unknown email would take to verify a real password.
            $this->passwordHasher->isPasswordValid($this->dummyUser(), $data->password);
            $this->fail();
        }

        if (!$this->passwordHasher->isPasswordValid($user, $data->password)) {
            $this->fail();
        }

        if (!$user->isActive()) {
            $this->fail();
        }

        if (!$this->authorizationChecker->isGranted(EmailVerifiedVoter::ATTRIBUTE, $user)) {
            // AC-7.5: enabling AUTH_REQUIRE_VERIFIED_EMAIL must not create a new oracle — same
            // generic failure as a wrong password.
            $this->fail();
        }

        return $this->issueSession($user, $request);
    }

    private function issueSession(User $user, Request $request): LoginOutput
    {
        $accessToken = $this->jwtManager->create($user);
        $issuedRefreshToken = $this->refreshTokenService->issueForUser($user);

        $isNative = $this->clientPlatform->isNative($request);

        if ($isNative) {
            $refreshTokenForBody = $issuedRefreshToken->plaintext;
        } else {
            $refreshTokenForBody = null;
            PendingCookieSubscriber::queue($request, $this->refreshCookieFactory->create($issuedRefreshToken->plaintext));
        }

        return new LoginOutput(
            accessToken: $accessToken,
            tokenType: 'Bearer',
            expiresIn: (int) $this->jwtTtl,
            refreshToken: $refreshTokenForBody,
        );
    }

    private function dummyUser(): User
    {
        $user = new User('dummy@example.invalid', self::DUMMY_HASH);

        return $user;
    }

    private function fail(): never
    {
        throw new UnauthorizedHttpException('Bearer', 'Invalid credentials.');
    }
}
