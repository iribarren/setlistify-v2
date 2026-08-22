<?php

declare(strict_types=1);

namespace App\Service\Streaming\Link;

use App\Entity\StreamingAccount;
use App\Repository\StreamingAccountRepository;
use App\Service\Provider\ProviderAvailability;
use App\Service\Provider\ProviderDisabledException;
use App\Service\Streaming\Exception\StreamingException;
use App\Service\Streaming\Exception\TokenExpiredException;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\StreamingProviderLocator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * D-79, US-4/US-5: the ONLY thing that refreshes a `StreamingAccount`'s tokens. No other consumer
 * of the port calls `refreshToken()` itself — everything asks {@see self::usableTokens()} for a
 * usable token pair and gets one, refreshed first if it was about to expire
 * (`STREAMING_TOKEN_REFRESH_SKEW`, AC-4.1).
 *
 * A per-account `symfony/lock` (AC-4.3) makes N concurrent callers on the same account cause at
 * most one real refresh: the first caller to acquire the lock refreshes and writes back; every
 * other caller blocks briefly, then re-reads the (now-fresh) account instead of refreshing again.
 * This matters beyond wasted calls — providers commonly invalidate a refresh token on use, so an
 * unguarded refresh race is a way to break the link, not just a wasted request.
 *
 * D-80: an unrecoverable grant failure (`TokenExpiredException` from the adapter) sets the account
 * to `needs_reauth` and clears its tokens (AC-5.1); any other `StreamingException` (network, 5xx,
 * rate limit — AC-5.2) is left alone and simply re-thrown, since a transient failure must never
 * demote a healthy account.
 */
final readonly class StreamingTokenManager
{
    private const string LOCK_RESOURCE_PREFIX = 'streaming_refresh_';
    private const float LOCK_TTL_SECONDS = 15.0;

    public function __construct(
        private StreamingProviderLocator $locator,
        private ProviderAvailability $providerAvailability,
        private StreamingAccountRepository $repository,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
        private int $refreshSkewSeconds,
    ) {
    }

    /**
     * @throws TokenExpiredException     the account needs reconnecting; status is already updated
     * @throws StreamingException        a transient provider failure — the account is untouched
     * @throws ProviderDisabledException AC-4.6: the provider is currently disabled — refresh is not
     *                                   attempted at all, and the account's status is left exactly
     *                                   as it was (a disabled provider is an operator state, not a
     *                                   broken grant, D-80)
     */
    public function usableTokens(StreamingAccount $account): ProviderTokens
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if (!$account->isExpiringWithin($this->refreshSkewSeconds, $now)) {
            return $this->tokensFromAccount($account);
        }

        $lock = $this->lockFactory->createLock(self::LOCK_RESOURCE_PREFIX.($account->getId() ?? 0), self::LOCK_TTL_SECONDS);
        $lock->acquire(true); // blocking — the caller genuinely cannot proceed without a usable token.

        try {
            // AC-4.3: re-check after acquiring the lock — another caller may have already refreshed
            // while this one was waiting.
            $this->entityManager->refresh($account);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());

            if (!$account->isExpiringWithin($this->refreshSkewSeconds, $now)) {
                return $this->tokensFromAccount($account);
            }

            return $this->refreshAndPersist($account, $now);
        } finally {
            $lock->release();
        }
    }

    private function refreshAndPersist(StreamingAccount $account, \DateTimeImmutable $now): ProviderTokens
    {
        // AC-4.6: a disabled provider never reaches the adapter, and never changes the account's
        // status — refusing here is the only place this check needs to live, since `usableTokens()`
        // routes every refresh through this method.
        if (!$this->providerAvailability->isAvailable($account->getProvider())) {
            throw new ProviderDisabledException($account->getProvider());
        }

        $provider = $this->locator->get($account->getProvider());

        try {
            $refreshed = $provider->refreshToken($this->tokensFromAccount($account));
        } catch (TokenExpiredException $e) {
            // D-80: unrecoverable — clear tokens, flip status, but keep the row.
            $account->markNeedsReauth($now);
            $this->repository->save($account);

            throw $e;
        } catch (StreamingException $e) {
            // AC-5.2: transient — never changes status, account is left exactly as it was.
            throw $e;
        }

        $account->applyRefreshedTokens($refreshed->accessToken, $refreshed->refreshToken, $refreshed->expiresAt, $now);
        $this->repository->save($account);

        return $this->tokensFromAccount($account);
    }

    private function tokensFromAccount(StreamingAccount $account): ProviderTokens
    {
        $accessToken = $account->getAccessToken();
        if (null === $accessToken) {
            // The account is already needs_reauth (tokens cleared) — nothing to refresh toward.
            throw new TokenExpiredException('Account has no stored access token — reconnect required.');
        }

        return new ProviderTokens(
            accessToken: $accessToken,
            refreshToken: $account->getRefreshToken(),
            expiresAt: $account->getExpiresAt() ?? \DateTimeImmutable::createFromInterface($this->clock->now()),
            scopes: $account->getScopes(),
        );
    }
}
