<?php

declare(strict_types=1);

namespace App\Service\Streaming\Spotify;

use App\Service\Streaming\Exception\NotFoundException;
use App\Service\Streaming\Exception\ProviderUnavailableException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Exception\RegionRestrictedException;
use App\Service\Streaming\Exception\StreamingException;
use App\Service\Streaming\Exception\TokenExpiredException;

/**
 * D-73, AC-10.1–AC-10.5: the ONLY place a provider's HTTP status/error body is read and turned into
 * the taxonomy — no status code or provider error shape escapes past this class. Driven from
 * recorded fixtures (AC-10.3, AC-12.2): 401 -> expired, 429 -> rate limited (with `Retry-After` as a
 * plain integer, AC-10.4), 404 -> not found, 403 -> region/market restricted, 5xx and anything
 * unclassified -> `ProviderUnavailableException` (AC-10.5, the catch-all).
 */
final class SpotifyErrorMapper
{
    /** @param array<string, list<string>> $headers */
    public function map(int $status, string $rawBody, array $headers): StreamingException
    {
        return match (true) {
            401 === $status => new TokenExpiredException($this->messageFrom($rawBody, 'Access token expired or invalid.')),
            403 === $status => new RegionRestrictedException($this->messageFrom($rawBody, 'Request rejected — region/market restricted.')),
            404 === $status => new NotFoundException($this->messageFrom($rawBody, 'Resource not found.')),
            429 === $status => new RateLimitedException($this->messageFrom($rawBody, 'Rate limited.'), $this->retryAfterSeconds($headers)),
            $status >= 500 => new ProviderUnavailableException($this->messageFrom($rawBody, \sprintf('Upstream error (%d).', $status))),
            default => new ProviderUnavailableException($this->messageFrom($rawBody, \sprintf('Unclassified provider response (%d).', $status))),
        };
    }

    public function mapTransportFailure(): StreamingException
    {
        return new ProviderUnavailableException('Provider connection failed (timeout or network error).');
    }

    /**
     * The OAuth token endpoint's error shape (`{"error": "invalid_grant", "error_description": …}`)
     * differs from the Web API's (`{"error": {"status": …, "message": …}}`) — {@see self::map()}
     * handles the latter. `invalid_grant` (revoked/expired refresh token, withdrawn scope) is the
     * one unrecoverable-grant case D-80 needs distinguished as `TokenExpiredException`; every other
     * token-endpoint failure falls back to the same transient/unclassified handling as the Web API.
     *
     * @param array<string, list<string>> $headers
     */
    public function mapTokenEndpointError(int $status, string $rawBody, array $headers): StreamingException
    {
        $errorCode = $this->tokenErrorCode($rawBody);

        if ('invalid_grant' === $errorCode) {
            return new TokenExpiredException($this->messageFrom($rawBody, 'The refresh token is no longer valid.'));
        }

        if (429 === $status) {
            return new RateLimitedException($this->messageFrom($rawBody, 'Rate limited.'), $this->retryAfterSeconds($headers));
        }

        if ($status >= 500) {
            return new ProviderUnavailableException($this->messageFrom($rawBody, \sprintf('Upstream error (%d).', $status)));
        }

        return new ProviderUnavailableException($this->messageFrom($rawBody, \sprintf('Unclassified token endpoint response (%d).', $status)));
    }

    private function tokenErrorCode(string $rawBody): ?string
    {
        try {
            $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) && \is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
    }

    private function messageFrom(string $rawBody, string $fallback): string
    {
        try {
            $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fallback;
        }

        if (\is_array($decoded) && isset($decoded['error']) && \is_array($decoded['error']) && \is_string($decoded['error']['message'] ?? null)) {
            return $decoded['error']['message'];
        }

        return $fallback;
    }

    /** @param array<string, list<string>> $headers */
    private function retryAfterSeconds(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;

        return null !== $value && is_numeric($value) ? (int) $value : null;
    }
}
