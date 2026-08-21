<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Security\RateLimiterGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * AC-4.1 (5 per 15 min per IP+email, 20 per 15 min per IP) and AC-4.3 (5 failed TOTP submissions
 * per 15 min per session) — consumed on `kernel.request`, **before** the firewall's authenticator
 * runs, so an over-the-limit attempt is fail-closed (429) rather than still spending a password/TOTP
 * guess (`App\Service\Security\RateLimiterGuard` already fails closed if Redis itself is down).
 *
 * Runs at a high priority specifically so it executes before
 * `Symfony\Component\Security\Http\Firewall`'s own `kernel.request` listener (priority 8).
 */
final class AdminAuthRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterGuard $rateLimiterGuard,
        #[Autowire(service: 'limiter.admin_login_credentials')]
        private readonly RateLimiterFactory $adminLoginCredentialsLimiter,
        #[Autowire(service: 'limiter.admin_login_ip')]
        private readonly RateLimiterFactory $adminLoginIpLimiter,
        #[Autowire(service: 'limiter.admin_totp_attempts')]
        private readonly RateLimiterFactory $adminTotpAttemptsLimiter,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST')) {
            return;
        }

        $loginCheckPath = $this->urlGenerator->generate('admin_login_check', referenceType: UrlGeneratorInterface::ABSOLUTE_PATH);
        $totpCheckPath = $this->urlGenerator->generate('admin_2fa_login_check', referenceType: UrlGeneratorInterface::ABSOLUTE_PATH);

        $path = $request->getPathInfo();

        if ($path === $loginCheckPath) {
            $email = strtolower(trim((string) $request->request->get('_username', '')));
            $ip = $request->getClientIp() ?? 'unknown';

            $this->rateLimiterGuard->consume($this->adminLoginIpLimiter, $ip);
            if ('' !== $email) {
                $this->rateLimiterGuard->consume($this->adminLoginCredentialsLimiter, $ip.'|'.$email);
            }

            return;
        }

        if ($path === $totpCheckPath) {
            $sessionId = $request->hasSession() ? $request->getSession()->getId() : 'no-session';
            $this->rateLimiterGuard->consume($this->adminTotpAttemptsLimiter, '' !== $sessionId ? $sessionId : 'no-session');
        }
    }
}
