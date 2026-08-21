<?php

declare(strict_types=1);

namespace App\Security\Admin;

use Scheb\TwoFactorBundle\Security\Http\Authentication\AuthenticationRequiredHandlerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * D-49: replaces scheb/2fa's `DefaultAuthenticationRequiredHandler` for the admin firewall
 * (`config/packages/security.yaml`'s `two_factor.authentication_required_handler`).
 *
 * scheb's own `TwoFactorAccessListener` runs *inside* the firewall's listener stack — the same
 * kernel.request priority slot as the whole firewall — so a plain `kernel.request` subscriber at
 * any priority cannot run early enough to override the redirect target it produces (Symfony's
 * `Firewall` class is itself the single `kernel.request` listener; everything inside it, including
 * this decision, happens in one pass before any *external* listener gets a chance). Replacing the
 * handler service is the supported seam for changing where a 2FA-pending request gets sent.
 *
 * `App\EventSubscriber\ForceTwoFactorEnrollmentSubscriber` remains as a second, narrower net for
 * direct navigation to the normal TOTP check form itself.
 */
final readonly class ForceEnrollmentAuthenticationRequiredHandler implements AuthenticationRequiredHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onAuthenticationRequired(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        $routeName = ($user instanceof AdminUser && !$user->hasTotpSecret())
            ? 'admin_2fa_enroll'
            : 'admin_2fa_login';

        return new RedirectResponse($this->urlGenerator->generate($routeName));
    }
}
