<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only class holding the outbound transport for this adapter — the same "one client, one
 * owner" shape the setlist.fm integration already established (D-58's pattern, generalised here
 * per D-73/AC-10.6). Two scoped clients: `$accountsClient` (`SPOTIFY_ACCOUNTS_BASE_URL`, the OAuth
 * token endpoint) and
 * `$apiClient` (`SPOTIFY_API_BASE_URL`, the Web API). Every outbound call carries the scoped
 * client's configured timeout and gets bounded, jittered retries on transient failures only (429,
 * 5xx, connection/timeout) — never on a 4xx that isn't 429 (AC-10.6).
 *
 * AC-7.3: never logs a request body or an `Authorization` header, on success or failure — only the
 * endpoint name, status and attempt count.
 */
final class SpotifyHttpClient
{
    private const int MAX_RETRIES = 2;
    private const float BASE_BACKOFF_SECONDS = 0.2;

    public function __construct(
        private readonly HttpClientInterface $accountsClient,
        private readonly HttpClientInterface $apiClient,
        private readonly SpotifyErrorMapper $errorMapper,
        private readonly LoggerInterface $streamingLogger,
    ) {
    }

    /**
     * @param array<string, scalar> $formParams
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function postForm(string $endpointLabel, string $path, array $formParams, array $headers = [], ?\Closure $errorMapper = null): array
    {
        return $this->request($this->accountsClient, $endpointLabel, 'POST', $path, [
            'body' => $formParams,
            'headers' => $headers,
        ], $errorMapper);
    }

    /** @param array<string, scalar> $query
     * @return array<string, mixed> */
    public function get(string $endpointLabel, string $path, array $query, string $accessToken): array
    {
        return $this->request($this->apiClient, $endpointLabel, 'GET', $path, [
            'query' => $query,
            'headers' => ['Authorization' => 'Bearer '.$accessToken],
        ]);
    }

    /** @param array<string, mixed> $jsonBody
     * @return array<string, mixed> */
    public function postJson(string $endpointLabel, string $path, array $jsonBody, string $accessToken): array
    {
        return $this->request($this->apiClient, $endpointLabel, 'POST', $path, [
            'json' => $jsonBody,
            'headers' => ['Authorization' => 'Bearer '.$accessToken],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(HttpClientInterface $client, string $endpointLabel, string $method, string $path, array $options, ?\Closure $errorMapper = null): array
    {
        $mapError = $errorMapper ?? \Closure::fromCallable([$this->errorMapper, 'map']);
        $attempt = 0;

        while (true) {
            try {
                $response = $client->request($method, $path, $options);
                $status = $response->getStatusCode();
                $rawBody = $response->getContent(false);

                if ($status >= 200 && $status < 300) {
                    if ('' === $rawBody) {
                        return [];
                    }

                    /** @var array<string, mixed> $decoded */
                    $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);

                    return $decoded;
                }

                if ($this->isTransientStatus($status) && $attempt < self::MAX_RETRIES) {
                    $this->sleep($this->retryDelaySeconds($response->getHeaders(false), $attempt));
                    ++$attempt;
                    continue;
                }

                $this->logFailure($endpointLabel, $status, $attempt);

                throw $mapError($status, $rawBody, $response->getHeaders(false));
            } catch (TransportExceptionInterface) {
                if ($attempt < self::MAX_RETRIES) {
                    $this->sleep($this->retryDelaySeconds([], $attempt));
                    ++$attempt;
                    continue;
                }

                $this->logFailure($endpointLabel, null, $attempt);

                throw $this->errorMapper->mapTransportFailure();
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
            return min((float) $retryAfter, 2.0);
        }

        $exponential = self::BASE_BACKOFF_SECONDS * (2 ** $attempt);
        $jitter = $exponential * (random_int(0, 100) / 100) * 0.5;

        return $exponential + $jitter;
    }

    private function sleep(float $seconds): void
    {
        usleep((int) (max(0, $seconds) * 1_000_000));
    }

    private function logFailure(string $endpointLabel, ?int $status, int $attempts): void
    {
        // Deliberately minimal — never the outbound URL, never headers, never a body (AC-7.3).
        $this->streamingLogger->warning('Streaming provider request failed', [
            'endpoint' => $endpointLabel,
            'lastStatus' => $status,
            'attempts' => $attempts + 1,
        ]);
    }
}
