<?php

declare(strict_types=1);

namespace App\Service\Streaming\Link;

use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\StreamingAccountRepository;
use App\Service\Security\RateLimiterGuard;
use App\Service\Streaming\Exception\StreamingException;
use App\Service\Streaming\StreamingProviderLocator;
use App\Service\Streaming\UnknownProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Start/complete the OAuth round trip (US-1, US-8). PKCE generation and the `state` lifecycle live
 * here — provider-agnostic (D-74) — not in any adapter.
 *
 * **The callback is never authenticated** (the provider redirects a bare browser navigation back to
 * `SPOTIFY_REDIRECT_URI`; there is no JWT on that request). The security boundary AC-8.1–AC-8.4
 * describe is therefore structural rather than a runtime comparison: `state` is generated
 * server-side, cryptographically random, bound to exactly one user id at `start()` time, stored in
 * Redis, and consumed exactly once (D-76). There is consequently no "current session" a completed
 * callback could belong to a *different* user than — the owner of any completed link is, by
 * construction, whichever user's `start()` call produced the `state` being completed, and nothing
 * else ever can be. AC-8.4's two-user test asserts exactly this: two users' pending links never
 * cross, however the requests are interleaved.
 *
 * A missing, unknown, expired or already-consumed `state` (AC-8.2, AC-8.3) is rejected the same
 * way as a provider-denied consent (AC-8.5) — a clean return to the client with, at most, a
 * `LinkResult` reference minted for whichever user WAS resolved from `state`; nothing distinguishes
 * *why* to an outside observer.
 */
final readonly class LinkFlowService
{
    public function __construct(
        private StreamingProviderLocator $locator,
        private PendingLinkStore $pendingLinkStore,
        private LinkResultStore $linkResultStore,
        private StreamingAccountRepository $accountRepository,
        private EntityManagerInterface $entityManager,
        private RateLimiterGuard $rateLimiterGuard,
        private RateLimiterFactory $streamingLinkStartLimiter,
        private ClockInterface $clock,
        /** @var array<string, string> provider key -> the one redirect URI registered for it (AC-1.9) */
        private array $redirectUrisByProvider,
        private string $webReturnUrl,
        private string $nativeReturnUrl,
    ) {
    }

    /**
     * AC-1.1, AC-8.6: returns the authorization URL the client should open. `$platform` is
     * `'native'`/`'web'` (`App\Service\Security\ClientPlatform`), recorded in the pending link so
     * the callback knows which return leg to use (D-75).
     */
    public function start(int $userId, string $providerKey, string $platform): string
    {
        // AC-8.6: fails closed if Redis (the limiter's storage) is unreachable.
        $this->rateLimiterGuard->consume($this->streamingLinkStartLimiter, (string) $userId);

        $provider = $this->locator->get($providerKey);
        $redirectUri = $this->redirectUriFor($providerKey);

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->deriveCodeChallenge($codeVerifier);

        $state = $this->pendingLinkStore->create($userId, $providerKey, $platform, $codeVerifier);

        return $provider->authorizationUrl($state, $redirectUri, $codeChallenge);
    }

    /**
     * AC-1.4–AC-1.10, AC-8.2–AC-8.5: completes (or cleanly rejects) the callback and returns the
     * full URL the browser should be redirected to next — a web route or a `setlistify://` deep
     * link, carrying at most a one-time opaque `ref` and nothing else (D-75).
     */
    public function completeCallback(string $providerKey, ?string $code, ?string $state, ?string $errorParam): string
    {
        $pending = null !== $state ? $this->pendingLinkStore->consume($state) : null;

        // AC-8.3: state missing/unknown/expired/already-consumed, or bound to a different provider
        // than this callback URL — one generic rejection, no ref to mint (no user resolved).
        if (null === $pending || $pending->provider !== $providerKey) {
            return $this->webReturnUrl;
        }

        if (null !== $errorParam) {
            // AC-8.5: a normal outcome, not an error — no record written.
            $ref = $this->linkResultStore->create($pending->userId, $providerKey, false, 'denied');

            return $this->buildReturnUrl($pending->platform, $ref);
        }

        if (null === $code) {
            $ref = $this->linkResultStore->create($pending->userId, $providerKey, false, 'missing_code');

            return $this->buildReturnUrl($pending->platform, $ref);
        }

        try {
            $provider = $this->locator->get($pending->provider);
            $tokens = $provider->exchangeCode($code, $this->redirectUriFor($pending->provider), $pending->codeVerifier);
        } catch (StreamingException|UnknownProviderException) {
            $ref = $this->linkResultStore->create($pending->userId, $providerKey, false, 'exchange_failed');

            return $this->buildReturnUrl($pending->platform, $ref);
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->persistAccount($pending->userId, $providerKey, $tokens, $now);

        $ref = $this->linkResultStore->create($pending->userId, $providerKey, true);

        return $this->buildReturnUrl($pending->platform, $ref);
    }

    public function resolveResult(int $userId, string $ref): ?LinkResult
    {
        return $this->linkResultStore->consume($ref, $userId);
    }

    private function persistAccount(int $userId, string $providerKey, \App\Service\Streaming\Model\ProviderTokens $tokens, \DateTimeImmutable $now): void
    {
        $existing = $this->accountRepository->findOneByUserAndProvider($userId, $providerKey);

        if (null !== $existing) {
            // AC-1.5: completing the flow twice updates the same row.
            $existing->relink(
                accessToken: $tokens->accessToken,
                refreshToken: $tokens->refreshToken,
                expiresAt: $tokens->expiresAt,
                scopes: $tokens->scopes,
                providerAccountId: $tokens->providerAccountId ?? $existing->getProviderAccountId(),
                providerDisplayName: $tokens->providerDisplayName,
                now: $now,
            );
            $this->accountRepository->save($existing);

            return;
        }

        /** @var User $userRef */
        $userRef = $this->entityManager->getReference(User::class, $userId);

        $account = new StreamingAccount(
            user: $userRef,
            provider: $providerKey,
            accessToken: $tokens->accessToken,
            refreshToken: $tokens->refreshToken,
            expiresAt: $tokens->expiresAt,
            scopes: $tokens->scopes,
            providerAccountId: $tokens->providerAccountId ?? '',
            providerDisplayName: $tokens->providerDisplayName,
            now: $now,
        );

        $this->accountRepository->save($account);
    }

    private function buildReturnUrl(string $platform, string $ref): string
    {
        $base = 'native' === $platform ? $this->nativeReturnUrl : $this->webReturnUrl;
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.'ref='.urlencode($ref);
    }

    private function redirectUriFor(string $providerKey): string
    {
        return $this->redirectUrisByProvider[$providerKey] ?? throw new UnknownProviderException($providerKey);
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function deriveCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
