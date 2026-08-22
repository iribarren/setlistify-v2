<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Band;
use App\Repository\BandRepository;
use App\Repository\SetlistRepository;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\SetlistGateway;
use App\Service\Setlist\SetlistNormalizer;
use App\Service\Setlist\SetlistRefreshRunLog;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * The **only** thing allowed to spend budget speculatively (D-65, US-10). Run nightly (README.md's
 * operations section documents the cron entry — this repo has no `symfony/scheduler` dependency,
 * so scheduling is a deployment-level cron job invoking this command, not an in-app scheduler).
 *
 * Scope, in order: bands attached to a concert that's upcoming or ended within the last 7 days,
 * nearest-to-today first (AC-10.1, AC-10.4); resolves identity for any that are still `unresolved`
 * or a `no_presence` band whose 30-day recheck window has elapsed (AC-5.4); then backfills each
 * resolved band's setlist index page by page, stopping as soon as a page's entries are all already
 * cached (AC-10.2 — date-bounded, not a blind full re-fetch).
 *
 * Spends at most `SETLISTFM_REFRESH_BUDGET_SHARE` of the daily budget (AC-10.3) and stops cleanly
 * once that share is spent, leaving the rest for user-triggered reads. Guarded by `symfony/lock`
 * (AC-10.5) so two overlapping runs can't double-spend. Idempotent (AC-10.8): a second run the same
 * night finds everything cached and spends almost nothing.
 */
#[AsCommand(
    name: 'app:setlist:refresh',
    description: 'Nightly, budget-capped refresh of setlist.fm data for bands attached to upcoming/recently-past concerts (D-65).',
)]
final class SetlistRefreshCommand extends Command
{
    private const int UPSTREAM_PAGE_SIZE = 20;
    private const int MAX_UPSTREAM_PAGES_PER_BAND = 10;
    private const int MAX_BANDS_PER_RUN = 500;
    private const float NIGHTLY_TOKEN_WAIT_SECONDS = 10.0;

    public function __construct(
        private readonly BandRepository $bandRepository,
        private readonly SetlistRepository $setlistRepository,
        private readonly BandIdentityResolver $resolver,
        private readonly SetlistGateway $gateway,
        private readonly SetlistNormalizer $normalizer,
        private readonly SetlistRefreshRunLog $runLog,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly int $dailyBudget,
        private readonly float $refreshBudgetShare,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('setlistfm:refresh', ttl: 3600.0);
        if (!$lock->acquire(false)) {
            $io->note('Another app:setlist:refresh run is already in progress — exiting (AC-10.5).');

            return Command::SUCCESS;
        }

        try {
            return $this->runRefresh($io);
        } finally {
            $lock->release();
        }
    }

    private function runRefresh(SymfonyStyle $io): int
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $windowStart = $now->modify('-7 days');
        $budgetCap = (int) floor($this->dailyBudget * $this->refreshBudgetShare);

        $bands = $this->bandRepository->findPrioritizedForRefresh($now, $windowStart, self::MAX_BANDS_PER_RUN);
        $io->writeln(\sprintf('Refresh scope: %d band(s), budget cap %d requests (%.0f%% of %d).', \count($bands), $budgetCap, $this->refreshBudgetShare * 100, $this->dailyBudget));

        $bandsAttempted = 0;
        $requestsSpent = 0;
        $entriesWritten = 0;

        foreach ($bands as $band) {
            if ($requestsSpent >= $budgetCap) {
                $io->note('Refresh budget share exhausted — stopping cleanly (AC-10.3).');
                break;
            }

            ++$bandsAttempted;
            [$spent, $written] = $this->refreshOneBand($band, $now, $budgetCap - $requestsSpent);
            $requestsSpent += $spent;
            $entriesWritten += $written;
        }

        $outcome = [
            'bandsAttempted' => $bandsAttempted,
            'requestsSpent' => $requestsSpent,
            'entriesWritten' => $entriesWritten,
            'budgetRemaining' => max(0, $budgetCap - $requestsSpent),
        ];
        $this->runLog->recordRun($outcome);

        $io->success(\sprintf(
            'app:setlist:refresh done: %d band(s) attempted, %d request(s) spent, %d setlist(s) written.',
            $bandsAttempted,
            $requestsSpent,
            $entriesWritten,
        ));

        return Command::SUCCESS;
    }

    /** @return array{int, int} [requestsSpent, entriesWritten] */
    private function refreshOneBand(Band $band, \DateTimeImmutable $now, int $remainingBudget): array
    {
        $spent = 0;

        if (Band::RESOLUTION_UNRESOLVED === $band->getSetlistfmResolutionState()) {
            $before = $band->getSetlistfmCheckedAt();
            $this->resolver->ensureResolved($band);
            $spent += (null === $before) ? 1 : 0; // best-effort accounting; exact count lives in the budget gate itself
        } elseif (Band::RESOLUTION_NO_PRESENCE === $band->getSetlistfmResolutionState()) {
            $this->resolver->recheckNoPresenceIfDue($band, $now);
        }

        if (Band::RESOLUTION_RESOLVED !== $band->getSetlistfmResolutionState()) {
            return [$spent, 0];
        }

        $mbid = $band->getSetlistfmMbid();
        \assert(null !== $mbid);

        $written = 0;
        $cached = $this->setlistRepository->countForBand($band);
        $upstreamPage = (int) floor($cached / self::UPSTREAM_PAGE_SIZE) + 1;

        for ($i = 0; $i < self::MAX_UPSTREAM_PAGES_PER_BAND && $spent < $remainingBudget; ++$i) {
            $fetch = $this->gateway->fetchArtistSetlistsPage($mbid, $upstreamPage, self::NIGHTLY_TOKEN_WAIT_SECONDS);
            ++$spent; // one attempt through the gate, whether it landed live or degraded

            if (null === $fetch->payload) {
                break; // degraded (budget/rate/breaker) — stop this band, move on (D-63)
            }

            $before = $this->setlistRepository->countForBand($band);
            $hydrated = $this->normalizer->hydrateSetlistsPage($band, $fetch->payload, $fetch->fetchedAt ?? $now);
            $after = $this->setlistRepository->countForBand($band);
            $written += max(0, $after - $before);

            $entriesOnPage = \count($hydrated['setlists']);
            $allAlreadyKnown = 0 === ($after - $before);

            if ($allAlreadyKnown || $entriesOnPage < self::UPSTREAM_PAGE_SIZE) {
                break; // AC-10.2: stop at the first fully-cached page, or the true last page
            }

            ++$upstreamPage;
        }

        return [$spent, $written];
    }
}
