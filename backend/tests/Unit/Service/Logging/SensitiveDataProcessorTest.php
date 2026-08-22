<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Logging;

use App\Service\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/** AC-11.2: no credential-shaped value survives this processor, at any nesting depth. */
final class SensitiveDataProcessorTest extends TestCase
{
    public function testRedactsTopLevelSensitiveKeys(): void
    {
        $processor = new SensitiveDataProcessor();

        $record = $this->record(context: [
            'password' => 'super-secret-password',
            'token' => 'abc123',
            'refresh_token' => 'def456',
            'authorization' => 'Bearer xyz',
            'email' => 'kept@example.com',
        ]);

        $result = ($processor)($record);

        self::assertSame('[REDACTED]', $result->context['password']);
        self::assertSame('[REDACTED]', $result->context['token']);
        self::assertSame('[REDACTED]', $result->context['refresh_token']);
        self::assertSame('[REDACTED]', $result->context['authorization']);
        self::assertSame('kept@example.com', $result->context['email'], 'non-sensitive fields are left alone');
    }

    /**
     * Streaming port and account linking (docs/specs/2026-08-22-streaming-port-and-account-linking.md,
     * AC-7.2): `access_token`, `refresh_token`, `code`, `code_verifier`, `client_secret` all
     * redacted, on top of `token` already covered above.
     */
    public function testRedactsProviderOAuthShapes(): void
    {
        $processor = new SensitiveDataProcessor();

        $record = $this->record(context: [
            'access_token' => 'BQD1abc...',
            'code' => 'AQD9xyz...',
            'code_verifier' => 'randomly-generated-verifier',
            'client_secret' => 'super-secret-client-secret',
            'statusCode' => 429,
            'postalCode' => '28001',
        ]);

        $result = ($processor)($record);

        self::assertSame('[REDACTED]', $result->context['access_token']);
        self::assertSame('[REDACTED]', $result->context['code']);
        self::assertSame('[REDACTED]', $result->context['code_verifier']);
        self::assertSame('[REDACTED]', $result->context['client_secret']);
        self::assertSame(429, $result->context['statusCode'], '"code" is exact-match only — unrelated *Code fields are kept');
        self::assertSame('28001', $result->context['postalCode']);
    }

    public function testRedactsNestedSensitiveKeys(): void
    {
        $processor = new SensitiveDataProcessor();

        $record = $this->record(context: [
            'user' => [
                'id' => 42,
                'credentials' => [
                    'password' => 'nested-secret',
                ],
            ],
        ]);

        $result = ($processor)($record);

        $user = $result->context['user'];
        self::assertIsArray($user);
        $credentials = $user['credentials'];
        self::assertIsArray($credentials);

        self::assertSame('[REDACTED]', $credentials['password']);
        self::assertSame(42, $user['id']);
    }

    public function testRedactsExtraArrayToo(): void
    {
        $processor = new SensitiveDataProcessor();

        $record = $this->record(extra: ['set-cookie' => 'refresh_token=abc123; HttpOnly']);

        $result = ($processor)($record);

        self::assertSame('[REDACTED]', $result->extra['set-cookie']);
    }

    public function testRedactsAnAuthorizationHeaderInterpolatedIntoTheMessage(): void
    {
        $processor = new SensitiveDataProcessor();

        $record = $this->record(message: 'Request failed with Authorization: Bearer abc.def.ghi and status 401');

        $result = ($processor)($record);

        self::assertStringNotContainsString('abc.def.ghi', $result->message);
        self::assertStringContainsString('[REDACTED]', $result->message);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function record(string $message = 'test message', array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'security',
            level: Level::Warning,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }
}
