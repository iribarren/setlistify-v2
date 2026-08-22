<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Service\Setlist\SetlistFmBudget;
use Psr\Log\NullLogger;

/**
 * US-7, US-9: the budget gate — daily counter exhaustion (AC-7.2, AC-8.6), rate limiting (AC-7.1,
 * AC-7.5), fail-closed on a broken Redis connection (AC-7.6, D-61), and the circuit breaker
 * (AC-9.4, D-64). Against real Redis (AC-13.5).
 */
final class SetlistFmBudgetTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testAcquireSucceedsUnderBudget(): void
    {
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new NullLogger(), ratePerSecond: 100, dailyBudget: 10, tokenWaitSeconds: 1.0);

        $decision = $budget->acquire();

        self::assertTrue($decision->allowed);
    }

    public function testDailyBudgetExhaustionRefusesFurtherRequests(): void
    {
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new NullLogger(), ratePerSecond: 1000, dailyBudget: 3, tokenWaitSeconds: 0.2);

        self::assertTrue($budget->acquire()->allowed);
        self::assertTrue($budget->acquire()->allowed);
        self::assertTrue($budget->acquire()->allowed);

        $refused = $budget->acquire();

        self::assertFalse($refused->allowed);
        self::assertSame('budget_exhausted', $refused->reason);
        self::assertNotNull($refused->resetAt);
    }

    public function testRateLimitDegradesWithinBoundedWaitInsteadOfBlockingForever(): void
    {
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new NullLogger(), ratePerSecond: 1, dailyBudget: 1000, tokenWaitSeconds: 0.2);

        self::assertTrue($budget->acquire()->allowed);

        $start = microtime(true);
        $second = $budget->acquire();
        $elapsed = microtime(true) - $start;

        self::assertFalse($second->allowed);
        self::assertSame('rate_limited', $second->reason);
        self::assertLessThan(1.0, $elapsed, 'A web request must never queue on the rate limiter beyond its bounded wait (AC-7.5, D-62).');
    }

    public function testConcurrentAcquisitionsNeverExceedTheConfiguredDailyBudget(): void
    {
        // AC-7.4: a concurrency-shaped check without spawning real OS threads — sequential
        // acquisitions against a shared Redis counter exercise the same atomic INCR path real
        // concurrent processes would.
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new NullLogger(), ratePerSecond: 1000, dailyBudget: 5, tokenWaitSeconds: 0.1);

        $allowed = 0;
        for ($i = 0; $i < 20; ++$i) {
            if ($budget->acquire()->allowed) {
                ++$allowed;
            }
        }

        self::assertSame(5, $allowed, 'Total successful acquisitions must never exceed the configured daily budget.');
    }

    public function testFailsClosedWhenRedisIsUnavailable(): void
    {
        $brokenRedis = new \Redis();
        // Never connected — every call throws, simulating Redis being unreachable (AC-7.6).
        $budget = new SetlistFmBudget($brokenRedis, $this->clock(), new NullLogger(), ratePerSecond: 2, dailyBudget: 1440, tokenWaitSeconds: 1.0);

        $decision = $budget->acquire();

        self::assertFalse($decision->allowed, 'Redis unavailable must fail closed — never fail open into an unlimited limiter.');
        self::assertSame('upstream_unavailable', $decision->reason);
    }

    public function testCircuitBreakerOpensAfterConsecutiveTransientFailuresAndBlocksFurtherAcquisitions(): void
    {
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new NullLogger(), ratePerSecond: 1000, dailyBudget: 1000, tokenWaitSeconds: 0.1);

        self::assertSame('closed', $budget->breakerState());

        for ($i = 0; $i < 5; ++$i) {
            $budget->recordTransientFailure();
        }

        self::assertSame('open', $budget->breakerState());

        $decision = $budget->acquire();
        self::assertFalse($decision->allowed);
        self::assertSame('upstream_unavailable', $decision->reason);
    }

    public function testSuccessResetsTheBreakerFailureCount(): void
    {
        $budget = new SetlistFmBudget($this->redis(), $this->clock(), new NullLogger(), ratePerSecond: 1000, dailyBudget: 1000, tokenWaitSeconds: 0.1);

        $budget->recordTransientFailure();
        $budget->recordTransientFailure();
        $budget->recordSuccess();
        $budget->recordTransientFailure();
        $budget->recordTransientFailure();

        self::assertSame('closed', $budget->breakerState(), 'A success in between must reset the consecutive-failure count.');
    }
}
