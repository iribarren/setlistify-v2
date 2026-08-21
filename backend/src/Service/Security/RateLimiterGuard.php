<?php

declare(strict_types=1);

namespace App\Service\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Consumes a rate limiter token and turns a rejection into a 429 (AC-9.2). Every credential
 * endpoint goes through this rather than calling a `RateLimiterFactory` directly, for one reason:
 * AC-9.6 requires the limiter to **fail closed** — if Redis (the limiter's storage) is unreachable,
 * the request must still be rejected with 429 rather than silently proceeding unlimited. A
 * `RateLimiterFactory` backed by a broken cache adapter throws; without this wrapper that exception
 * would surface as an unrelated 500.
 */
final readonly class RateLimiterGuard
{
    public function __construct(
        private LoggerInterface $securityLogger,
    ) {
    }

    public function consume(RateLimiterFactory $factory, string $key): void
    {
        try {
            $limiter = $factory->create($key);
            $limit = $limiter->consume();
        } catch (\Throwable $e) {
            $this->securityLogger->error('Rate limiter storage unavailable — failing closed', [
                'exception' => $e::class,
            ]);
            throw new TooManyRequestsHttpException(null, 'Too many requests.');
        }

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many requests.');
        }
    }

    /** For the rare case the caller already resolved a {@see LimiterInterface}. */
    public function consumeLimiter(LimiterInterface $limiter): void
    {
        try {
            $limit = $limiter->consume();
        } catch (\Throwable $e) {
            $this->securityLogger->error('Rate limiter storage unavailable — failing closed', [
                'exception' => $e::class,
            ]);
            throw new TooManyRequestsHttpException(null, 'Too many requests.');
        }

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException($limit->getRetryAfter()->getTimestamp() - time(), 'Too many requests.');
        }
    }
}
