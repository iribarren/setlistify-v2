<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Admin\AdminUser;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * D-49: an admin account with no TOTP secret can reach only the enrollment route. A partially
 * authenticated (2FA-in-progress) session for such an account is redirected here away from every
 * other admin URL, including scheb's own TOTP check form — which {@see AdminUser} deliberately
 * makes mechanically reachable-but-unwinnable (a placeholder secret) as a defence-in-depth backstop,
 * not the primary gate. This subscriber is the primary gate.
 *
 * Runs after the security firewall (`kernel.request` priority 8) has restored/authenticated the
 * token, but before routing dispatches to a controller.
 */
final class ForceTwoFactorEnrollmentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $adminPathPrefix,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', -10]];
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

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof AdminUser || $user->hasTotpSecret()) {
            return;
        }

        $allowedPaths = [
            $this->urlGenerator->generate('admin_2fa_enroll', referenceType: UrlGeneratorInterface::ABSOLUTE_PATH),
            $this->urlGenerator->generate('admin_2fa_enroll_confirm', referenceType: UrlGeneratorInterface::ABSOLUTE_PATH),
            $this->urlGenerator->generate('admin_logout', referenceType: UrlGeneratorInterface::ABSOLUTE_PATH),
        ];

        if (\in_array($request->getPathInfo(), $allowedPaths, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($allowedPaths[0]));
    }
}
