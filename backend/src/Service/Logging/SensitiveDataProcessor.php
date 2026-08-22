<?php

declare(strict_types=1);

namespace App\Service\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts credential-shaped values from every log record, globally (AC-11.2). Tagged
 * `monolog.processor` in `config/services.yaml`, so it runs on every channel — there is no
 * per-channel opt-out to forget.
 *
 * Walks `context` and `extra` recursively, redacting any key that looks like a credential
 * regardless of nesting depth, and additionally scrubs the message string for an
 * `Authorization: Bearer …` / `Set-Cookie: …` header that ended up interpolated into free text.
 */
final class SensitiveDataProcessor implements ProcessorInterface
{
    private const string REDACTED = '[REDACTED]';

    /** Matched case-insensitively against array keys, anywhere in context/extra. */
    private const array SENSITIVE_KEYS = [
        'password',
        'plainpassword',
        'token',
        'refresh_token',
        'refreshtoken',
        'access_token',
        'accesstoken',
        'authorization',
        'set-cookie',
        'cookie',
        // setlist.fm integration (docs/specs/2026-08-22-setlistfm-integration.md, US-12): the
        // outbound `x-api-key` header must never appear in a log line, in any casing/nesting.
        'x-api-key',
        'apikey',
        'api_key',
        // Streaming port and account linking (docs/specs/2026-08-22-streaming-port-and-account-linking.md,
        // AC-7.2): provider OAuth token/credential shapes, in any casing/nesting.
        'code_verifier',
        'codeverifier',
        'client_secret',
        'clientsecret',
    ];

    /**
     * Exact-match only (never substring) — unlike the credential-shaped keys above, "code" alone is
     * common enough as an unrelated field name (`postalCode`, `statusCode`, `countryCode`, …) that
     * substring matching would over-redact. AC-7.2 only requires the literal OAuth `code` param.
     */
    private const array EXACT_MATCH_SENSITIVE_KEYS = [
        'code',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->redactArray($record->context);
        $extra = $this->redactArray($record->extra);
        $message = $this->redactMessage($record->message);

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed> */
    private function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = self::REDACTED;
                continue;
            }

            $result[$key] = \is_array($value) ? $this->redactArray($value) : $value;
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (\in_array($normalized, self::EXACT_MATCH_SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function redactMessage(string $message): string
    {
        $message = preg_replace('/(Authorization:\s*Bearer\s+)\S+/i', '$1'.self::REDACTED, $message) ?? $message;

        return preg_replace('/(Set-Cookie:\s*)\S+/i', '$1'.self::REDACTED, $message) ?? $message;
    }
}
