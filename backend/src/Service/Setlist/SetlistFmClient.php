<?php

declare(strict_types=1);

namespace App\Service\Setlist;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The transport (D-58) — wired to the `setlistfm.client` scoped HTTP client
 * (`config/packages/setlistfm.yaml`) which already carries the base URI, `x-api-key` header,
 * `Accept: application/json` and timeouts (AC-9.1). **Not consumed by anything outside
 * `App\Service\Setlist\`** — `SetlistCache` is its only caller; see
 * `App\Tests\Unit\Service\Setlist\SetlistGatewayIsOnlyDoorTest` (AC-6.5, D-58).
 *
 * Every call passes through {@see SetlistFmBudget::acquire()} first (US-7) — this class cannot
 * issue a request without a token. Retries are capped, jittered, and apply only to transient
 * failures (429, 5xx, connection/timeout) — never to a 404 or any other 4xx (US-9).
 *
 * US-12: the API key never appears in a log line or an exception message here. Only the endpoint
 * name, HTTP status and attempt number are ever logged (AC-12.2, AC-12.3) — never the outbound URL
 * with headers, never `$response->getInfo()`'s raw request/response header dump.
 */
final class SetlistFmClient
{
    private const int MAX_RETRIES = 2;
    private const float BASE_BACKOFF_SECONDS = 0.2;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SetlistFmBudget $budget,
        private readonly LoggerInterface $setlistfmLogger,
        /**
         * Not read by any request-handling logic in this class — only present so
         * App\Tests\Unit\Service\Setlist\ApiKeyNeverLoggedTest can assert, from outside, that no
         * code path threads this value into a log call. {@see self::assertConfigured()} touches it
         * once at construction time (a presence check, never logged, never compared against
         * anything derived from a request) purely so static analysis doesn't flag it dead.
         */
        private readonly string $apiKeyForRedactionCheck,
    ) {
        $this->assertConfigured();
    }

    private function assertConfigured(): void
    {
        \assert('' !== $this->apiKeyForRedactionCheck, 'SETLISTFM_API_KEY must not be empty.');
    }

    /**
     * @param array<string, scalar> $query
     */
    public function request(string $endpoint, string $path, array $query = [], ?float $waitOverrideSeconds = null): SetlistFmClientResult
    {
        $attempt = 0;

        while (true) {
            $decision = $this->budget->acquire($waitOverrideSeconds);
            if (!$decision->allowed) {
                \assert(null !== $decision->reason);

                return SetlistFmClientResult::degraded($decision->reason, $decision->resetAt);
            }

            try {
                $response = $this->httpClient->request('GET', $path, ['query' => $query]);
                $status = $response->getStatusCode();

                if (200 === $status) {
                    $this->budget->recordSuccess();

                    /** @var array<string, mixed> $payload */
                    $payload = $response->toArray(false);

                    return SetlistFmClientResult::success($payload, $status);
                }

                if (404 === $status) {
                    // A 404 is a legitimate, non-transient answer (e.g. "no such setlist") — not a
                    // breaker signal (AC-9.2).
                    $this->budget->recordSuccess();

                    return SetlistFmClientResult::notFound();
                }

                if ($this->isTransientStatus($status)) {
                    $this->budget->recordTransientFailure();

                    if ($attempt >= self::MAX_RETRIES) {
                        $this->logDegraded($endpoint, $status, $attempt);

                        return SetlistFmClientResult::degraded('upstream_unavailable', null);
                    }

                    $this->sleep($this->retryDelaySeconds($response->getHeaders(false), $attempt));
                    ++$attempt;
                    continue;
                }

                // Any other 4xx: not retried, not a breaker signal (AC-9.2).
                $this->budget->recordSuccess();

                return SetlistFmClientResult::clientError($status);
            } catch (TransportExceptionInterface) {
                $this->budget->recordTransientFailure();

                if ($attempt >= self::MAX_RETRIES) {
                    $this->logDegraded($endpoint, null, $attempt);

                    return SetlistFmClientResult::degraded('upstream_unavailable', null);
                }

                $this->sleep($this->retryDelaySeconds([], $attempt));
                ++$attempt;
            }
        }
    }

    private function isTransientStatus(int $status): bool
    {
        return 429 === $status || $status >= 500;
    }

    /** @param array<string, list<string>> $headers */
    private function retryDelaySeconds(array $headers, int $attempt): float
    {
        $retryAfter = $headers['retry-after'][0] ?? null;
        if (null !== $retryAfter && is_numeric($retryAfter)) {
            return (float) $retryAfter;
        }

        // Exponential backoff with jitter (AC-9.3), capped low since retries themselves spend
        // real budget (D-64).
        $exponential = self::BASE_BACKOFF_SECONDS * (2 ** $attempt);
        $jitter = $exponential * (random_int(0, 100) / 100) * 0.5;

        return $exponential + $jitter;
    }

    private function sleep(float $seconds): void
    {
        usleep((int) (max(0, $seconds) * 1_000_000));
    }

    private function logDegraded(string $endpoint, ?int $status, int $attempts): void
    {
        // Deliberately minimal: endpoint name + status code only — never the outbound URL, never
        // headers (AC-12.3).
        $this->setlistfmLogger->warning('setlist.fm request degraded after exhausting retries', [
            'endpoint' => $endpoint,
            'lastStatus' => $status,
            'attempts' => $attempts + 1,
        ]);
    }
}
