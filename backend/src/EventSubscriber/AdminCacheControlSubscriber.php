<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * AC-1.7 / AC-9.5: every admin response carries `Cache-Control: no-store` — the back button must
 * never reach a cached authenticated page, and a revealed email must never be cacheable either.
 */
final class AdminCacheControlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $adminPathPrefix,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), $this->adminPathPrefix)) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
    }
}
