<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * D-54: an 8-hour **absolute** session lifetime, independent of the 30-minute idle timeout
 * (`gc_maxlifetime` in config/packages/framework.yaml). Symfony's session component has no native
 * concept of "started at", so this stamps one into the session at login and checks it on every
 * subsequent admin request.
 */
final class AdminSessionLifetimeSubscriber implements EventSubscriberInterface
{
    private const string SESSION_KEY = '_admin_session_started_at';
    private const int ABSOLUTE_LIFETIME_SECONDS = 8 * 3600;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $adminPathPrefix,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -20],
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('admin' !== $event->getFirewallName()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, time());
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), $this->adminPathPrefix)) {
            return;
        }

        if (null === $this->tokenStorage->getToken()) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $startedAt = $session->get(self::SESSION_KEY);

        if (!\is_int($startedAt)) {
            // Session predates this stamp (or was restored without one) — stamp it now rather
            // than treat it as immediately expired.
            $session->set(self::SESSION_KEY, time());

            return;
        }

        if (time() - $startedAt > self::ABSOLUTE_LIFETIME_SECONDS) {
            $session->invalidate();
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('admin_login', referenceType: UrlGeneratorInterface::ABSOLUTE_PATH),
            ));
        }
    }
}
