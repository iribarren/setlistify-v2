<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the polling contract's `ETag`/304 and `Retry-After` (spec 14 §6, D-150), and the
 * "existing live job" status override (D-129: a second `POST` for the same live generation returns
 * 200, never 201/409) — all driven by request attributes the provider/processor set, so this stays
 * a thin, generically-testable mechanism rather than framework-specific magic inside a provider.
 *
 * Must implement {@see EventSubscriberInterface} for Symfony's autoconfiguration to ever register
 * this as a `kernel.response` listener in the first place — without it (a bug fixed in this branch:
 * the class previously only declared the static `getSubscribedEvents()` method without the
 * interface), this class compiles and even satisfies the interface's shape by duck typing, but is
 * never wired to any event at all, so none of the polling contract it exists to implement ever ran.
 */
final class PlaylistResponseHeadersSubscriber implements EventSubscriberInterface
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

        $statusOverride = $request->attributes->get('_playlist_status_override');
        if (\is_int($statusOverride)) {
            $response->setStatusCode($statusOverride);
        }

        $retryAfter = $request->attributes->get('_playlist_retry_after');
        if (\is_int($retryAfter)) {
            // Set before the possible 304 below — a client polling a `blocked`-adjacent active
            // state still needs to know when to poll again even when nothing else has changed.
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        $etag = $request->attributes->get('_playlist_etag');
        if (\is_string($etag)) {
            $response->setEtag($etag);
            $response->isNotModified($request); // Mutates $response to 304 + empty body on a match.
        }
    }
}
