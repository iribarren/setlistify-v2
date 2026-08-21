<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Admin\AdminLockoutTracker;
use App\Security\Admin\AdminUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * AC-4.2 (lockout counting) and AC-4.4 (every failed admin login and lockout logged at `warning`,
 * every successful one at `info`) — scoped to the `admin` firewall only via `getFirewallName()`, so
 * a failed API login never touches this account-lockout state.
 */
final class AdminLoginAttemptSubscriber implements EventSubscriberInterface
{
    private const string FIREWALL_NAME = 'admin';

    public function __construct(
        private readonly AdminLockoutTracker $lockoutTracker,
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => 'onLoginFailure',
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (self::FIREWALL_NAME !== $event->getFirewallName()) {
            return;
        }

        $email = $this->resolveEmail($event->getPassport()?->getUser()?->getUserIdentifier(), $event);
        $ip = $event->getRequest()->getClientIp();

        $justLocked = '' !== $email && $this->lockoutTracker->recordFailure($email);

        $this->securityLogger->warning('Admin login failed', [
            'ip' => $ip,
            'reason' => $event->getException()->getMessageKey(),
        ]);

        if ($justLocked) {
            $this->securityLogger->warning('Admin account locked after repeated failures', ['ip' => $ip]);
        }
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (self::FIREWALL_NAME !== $event->getFirewallName()) {
            return;
        }

        $user = $event->getUser();
        if ($user instanceof AdminUser) {
            $this->lockoutTracker->recordSuccess($user->getUserIdentifier());
        }

        $this->securityLogger->info('Admin login succeeded', [
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }

    private function resolveEmail(?string $fromPassport, LoginFailureEvent $event): string
    {
        if (null !== $fromPassport && '' !== $fromPassport) {
            return strtolower(trim($fromPassport));
        }

        $fromRequest = $event->getRequest()->request->get('_username');

        return \is_string($fromRequest) ? strtolower(trim($fromRequest)) : '';
    }
}
