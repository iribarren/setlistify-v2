<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Applies the browser-facing security headers to every response, globally, so a future endpoint
 * cannot ship without them (US-9, AC-9.6). Scoped by request path so the strict, JSON-API policy
 * never breaks `/api/docs`'s own Swagger UI assets (AC-9.5, R-8) — the docs route gets a relaxed
 * `Content-Security-Policy` that still denies framing, inline `<script>` and third-party origins.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $path = $event->getRequest()->getPathInfo();
        $isDocs = 1 === preg_match('#^/api/docs#', $path);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', $isDocs ? $this->docsContentSecurityPolicy() : $this->apiContentSecurityPolicy());

        if ('prod' === $this->kernel->getEnvironment()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    /**
     * A pure JSON API never renders anything a browser executes — deny everything (AC-9.4).
     */
    private function apiContentSecurityPolicy(): string
    {
        return "default-src 'none'; frame-ancestors 'none'";
    }

    /**
     * `/api/docs` renders API Platform's own Swagger UI, which needs same-origin scripts/styles,
     * inline styles it injects at runtime, and `unsafe-eval` (`swagger-ui-bundle.js` uses
     * `new Function(...)` internally). Still same-origin only, still no framing (AC-9.5, R-8).
     */
    private function docsContentSecurityPolicy(): string
    {
        return "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; frame-ancestors 'none'";
    }
}
