<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Instant setlist refresh's status-override/`Retry-After` mechanism
 * (docs/specs/2026-08-27-instant-setlist-refresh.md, D-260, D-262, AC-3.5) — same shape as
 * `App\EventSubscriber\PlaylistResponseHeadersSubscriber`: driven by request attributes the
 * processor/provider set, kept generically testable rather than framework-specific magic embedded
 * in a provider.
 */
final class SetlistRefreshResponseHeadersSubscriber implements EventSubscriberInterface
{
    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        $statusOverride = $request->attributes->get('_setlist_refresh_status_override');
        if (\is_int($statusOverride)) {
            $response->setStatusCode($statusOverride);
        }

        $retryAfter = $request->attributes->get('_setlist_refresh_retry_after');
        if (\is_int($retryAfter)) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }
    }
}
