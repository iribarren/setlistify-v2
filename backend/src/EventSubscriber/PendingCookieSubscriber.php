<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Attaches cookies queued by a state processor onto the outgoing response.
 *
 * API Platform builds the `Response` object for an operation *after* its processor returns, so a
 * processor (e.g. `LoginProcessor`, `RefreshProcessor`, `LogoutProcessor`) cannot set a cookie
 * directly. Instead it stores the `Cookie` objects it wants on this request attribute; this
 * subscriber — running late in `kernel.response` — copies them onto the real response headers.
 */
final class PendingCookieSubscriber implements EventSubscriberInterface
{
    public const string REQUEST_ATTRIBUTE = '_pending_cookies';

    /**
     * Queues a cookie to be attached to the eventual response. The single place every state
     * processor that needs to set/clear the refresh cookie should call — keeps the
     * `array<string, mixed>` attribute bag's loose typing contained to this one method instead of
     * repeated in every caller.
     */
    public static function queue(Request $request, Cookie $cookie): void
    {
        $cookies = self::pending($request);
        $cookies[] = $cookie;
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $cookies);
    }

    /** @return list<Cookie> */
    private static function pending(Request $request): array
    {
        $raw = $request->attributes->get(self::REQUEST_ATTRIBUTE, []);
        if (!\is_array($raw)) {
            return [];
        }

        $cookies = [];
        foreach ($raw as $item) {
            if ($item instanceof Cookie) {
                $cookies[] = $item;
            }
        }

        return $cookies;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach (self::pending($event->getRequest()) as $cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}
