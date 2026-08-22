<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * D-42: when `ADMIN_IP_ALLOWLIST` is non-empty, a request from an IP outside the listed CIDR ranges
 * is rejected with a **404** (not 403 — an outsider learns nothing about the prefix existing),
 * before authentication runs. Empty means unrestricted (correct for local dev and CI).
 *
 * Runs before the security firewall (`kernel.request` priority 8) and reads the client IP through
 * Symfony's trusted-proxy-aware `Request::getClientIp()` — never a raw header — so the guarantee
 * survives a proxy misconfiguration (R-7).
 */
final class AdminIpAllowlistListener implements EventSubscriberInterface
{
    /** @var list<string> */
    private readonly array $allowlist;

    public function __construct(
        string $allowlistCsv,
        private readonly string $adminPathPrefix,
        private readonly LoggerInterface $securityLogger,
        private readonly string $appEnv,
    ) {
        $this->allowlist = array_values(array_filter(array_map('trim', explode(',', $allowlistCsv))));

        if ('prod' === $this->appEnv && [] === $this->allowlist) {
            $this->securityLogger->error('ADMIN_IP_ALLOWLIST is empty in production — the admin backoffice is reachable from any IP (D-42).');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 30]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || [] === $this->allowlist) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), $this->adminPathPrefix)) {
            return;
        }

        $clientIp = $request->getClientIp();
        if (null !== $clientIp && IpUtils::checkIp($clientIp, $this->allowlist)) {
            return;
        }

        $this->securityLogger->warning('Admin request rejected — IP outside ADMIN_IP_ALLOWLIST', [
            'ip' => $clientIp,
            'path' => $request->getPathInfo(),
        ]);

        // 404, not 403 (D-42) — an outsider must not learn the prefix even exists.
        throw new NotFoundHttpException();
    }
}
