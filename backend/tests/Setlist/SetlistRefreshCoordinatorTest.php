<?php

declare(strict_types=1);

namespace App\Tests\Setlist;

use App\Entity\Band;
use App\Entity\User;
use App\Service\Concert\BandResolver;
use App\Service\Setlist\SetlistFmBudget;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\Service\Setlist\SetlistRefreshMetrics;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The three throttles and the single-flight/idempotent-repeat behaviour
 * (docs/specs/2026-08-27-instant-setlist-refresh.md, D-259, D-262, AC-5.1..AC-5.6).
 */
final class SetlistRefreshCoordinatorTest extends SetlistIntegrationTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetSetlistfmRedis();
        $this->resetSetlistfmDatabase();
    }

    public function testFirstTriggerIsAcceptedAndWritesAQueuedRecord(): void
    {
        $coordinator = $this->makeCoordinator();
        $band = $this->persistBand();
        $user = $this->persistUser();

        $decision = $coordinator->trigger($band, $user, new \DateTimeImmutable());

        self::assertSame('accepted', $decision->kind);
        self::assertNotNull($decision->record);
        self::assertSame('queued', $decision->record->state);
    }

    /** D-262/AC-1.5: a second trigger while one is in flight returns the same record, never a refusal. */
    public function testSecondTriggerWhileInFlightReturnsTheExistingRecordNotARefusal(): void
    {
        $coordinator = $this->makeCoordinator();
        $band = $this->persistBand();
        $user = $this->persistUser();

        $first = $coordinator->trigger($band, $user, new \DateTimeImmutable());
        self::assertSame('accepted', $first->kind);

        $second = $coordinator->trigger($band, $user, new \DateTimeImmutable());
        self::assertSame('alreadyInFlight', $second->kind);
        self::assertSame($first->record?->requestedAt->format(\DateTimeInterface::ATOM), $second->record?->requestedAt->format(\DateTimeInterface::ATOM));
    }

    /** AC-5.1/AC-5.5: the cooldown refuses a second trigger for the SAME band once the first is terminal. */
    public function testCooldownRefusesASecondTriggerForTheSameBandAcrossUsers(): void
    {
        $coordinator = $this->makeCoordinator(cooldownSeconds: 3600);
        $band = $this->persistBand();
        $firstUser = $this->persistUser();
        $secondUser = $this->persistUser();

        $first = $coordinator->trigger($band, $firstUser, new \DateTimeImmutable());
        self::assertSame('accepted', $first->kind);
        $coordinator->markSucceeded($band->getId() ?? 0, Band::RESOLUTION_RESOLVED, [], \App\Service\Setlist\CachedFetch::live([], new \DateTimeImmutable()), new \DateTimeImmutable());

        $second = $coordinator->trigger($band, $secondUser, new \DateTimeImmutable());
        self::assertSame('refused', $second->kind);
        self::assertSame('cooldown_active', $second->refusedReason);
    }

    /** AC-5.5: driving one user to the daily cap refuses their next trigger with daily_limit_reached. */
    public function testDailyCapRefusesAfterTheConfiguredNumberOfAcceptedTriggers(): void
    {
        $coordinator = $this->makeCoordinator(dailyPerUserCap: 2);
        $user = $this->persistUser();

        foreach ([1, 2] as $i) {
            $band = $this->persistBand();
            $decision = $coordinator->trigger($band, $user, new \DateTimeImmutable());
            self::assertSame('accepted', $decision->kind, "trigger #{$i} should be accepted");
            $coordinator->markSucceeded($band->getId() ?? 0, Band::RESOLUTION_RESOLVED, [], \App\Service\Setlist\CachedFetch::live([], new \DateTimeImmutable()), new \DateTimeImmutable());
        }

        $thirdBand = $this->persistBand();
        $decision = $coordinator->trigger($thirdBand, $user, new \DateTimeImmutable());
        self::assertSame('refused', $decision->kind);
        self::assertSame('daily_limit_reached', $decision->refusedReason);
    }

    /** AC-5.5: driving the daily budget to within the reserve refuses with budget_reserved. */
    public function testBudgetReserveRefusesWhenRemainingBudgetIsBelowTheReserveShare(): void
    {
        $spentBudget = $this->makeBudget(dailyBudget: 100);
        // Spend 95 of 100 — only 5% remains, below a 10% reserve.
        for ($i = 0; $i < 95; ++$i) {
            $spentBudget->acquire();
        }

        $coordinator = $this->makeCoordinator(budgetReserveShare: 0.10, budget: $spentBudget);
        $band = $this->persistBand();
        $user = $this->persistUser();

        $decision = $coordinator->trigger($band, $user, new \DateTimeImmutable());

        self::assertSame('refused', $decision->kind);
        self::assertSame('budget_reserved', $decision->refusedReason);
    }

    /** AC-5.2: a refused trigger costs the user nothing — cooldown/cap are untouched by a refusal. */
    public function testARefusalDoesNotConsumeCooldownOrDailyCap(): void
    {
        $spentBudget = $this->makeBudget(dailyBudget: 100);
        for ($i = 0; $i < 95; ++$i) {
            $spentBudget->acquire();
        }
        $coordinator = $this->makeCoordinator(budgetReserveShare: 0.10, budget: $spentBudget);
        $band = $this->persistBand();
        $user = $this->persistUser();

        $refused = $coordinator->trigger($band, $user, new \DateTimeImmutable());
        self::assertSame('refused', $refused->kind);

        // Now with a plentiful budget, the SAME band/user should still be triggerable — proving
        // the refusal above set neither the cooldown nor the daily-cap counter.
        $plentifulCoordinator = $this->makeCoordinator();
        $decision = $plentifulCoordinator->trigger($band, $user, new \DateTimeImmutable());
        self::assertSame('accepted', $decision->kind);
    }

    private function persistBand(): Band
    {
        $name = \sprintf('Coordinator Test %s', bin2hex(random_bytes(6)));
        $band = new Band($name, BandResolver::normalize($name), new \DateTimeImmutable());
        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    private function persistUser(): User
    {
        $user = new User(\sprintf('coord-%s@example.test', bin2hex(random_bytes(6))), 'irrelevant-hash');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function makeBudget(int $dailyBudget = 1_000_000): SetlistFmBudget
    {
        return new SetlistFmBudget(
            $this->redis(),
            $this->clock(),
            new \Psr\Log\NullLogger(),
            ratePerSecond: 1000,
            dailyBudget: $dailyBudget,
            tokenWaitSeconds: 1.0,
        );
    }

    private function makeCoordinator(
        int $cooldownSeconds = 3600,
        int $dailyPerUserCap = 5,
        float $budgetReserveShare = 0.10,
        ?SetlistFmBudget $budget = null,
    ): SetlistRefreshCoordinator {
        return new SetlistRefreshCoordinator(
            $this->redis(),
            $budget ?? $this->makeBudget(),
            self::getContainer()->get(SetlistRefreshMetrics::class),
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            $cooldownSeconds,
            $dailyPerUserCap,
            $budgetReserveShare,
        );
    }
}
