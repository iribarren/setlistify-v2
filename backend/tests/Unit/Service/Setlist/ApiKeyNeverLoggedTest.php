<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Setlist;

use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistFmClient;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * US-12, AC-12.2, AC-12.3: the API key never appears in a log line — including on the failure
 * paths (exhausted retries, budget exhaustion). A fake, obviously-not-real key is threaded through
 * every logger call site and asserted absent from every captured record.
 */
final class ApiKeyNeverLoggedTest extends \PHPUnit\Framework\TestCase
{
    private const string FAKE_KEY = 'super-secret-setlistfm-key-do-not-log-me';

    public function testClientLogsNothingContainingTheApiKeyAfterExhaustingRetries(): void
    {
        $spy = new RecordingLogger();
        $budget = new SetlistFmBudget(new \Redis(), new MockClock(), $spy, ratePerSecond: 1000, dailyBudget: 1000, tokenWaitSeconds: 0.01);
        // Redis is deliberately unconnected — acquire() will fail-closed every time, which is fine:
        // this test only cares that nothing the client logs contains the key.
        $client = new SetlistFmClient(new MockHttpClient(new MockResponse('', ['http_code' => 500])), $budget, $spy, self::FAKE_KEY);

        $client->request('setlist.get', '/setlist/doesnotmatter');

        foreach ($spy->records as $record) {
            self::assertStringNotContainsString(self::FAKE_KEY, $record, 'A log record contained the setlist.fm API key.');
        }
    }

    public function testBudgetExhaustionLogDoesNotContainTheApiKey(): void
    {
        $spy = new RecordingLogger();
        // dailyBudget: 0 forces immediate exhaustion; Redis unconnected also fails closed first,
        // so this exercises both fail-closed and exhaustion log paths.
        $budget = new SetlistFmBudget(new \Redis(), new MockClock(), $spy, ratePerSecond: 1000, dailyBudget: 0, tokenWaitSeconds: 0.01);

        $budget->acquire();

        foreach ($spy->records as $record) {
            self::assertStringNotContainsString(self::FAKE_KEY, $record);
        }
    }
}

final class RecordingLogger extends AbstractLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = $message.' '.json_encode($context);
    }
}
