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
