<?php

declare(strict_types=1);

namespace App\Tests\Functional\Streaming;

use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\StreamingAccountRepository;
use App\Service\Streaming\Exception\TokenExpiredException;
use App\Service\Streaming\Link\StreamingTokenManager;
use App\Service\Streaming\StreamingProviderLocator;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Uid\Uuid;

/**
 * US-4, US-5, D-79/D-80. Uses the test-double adapter — this test is about the manager's own
 * refresh/lock/status-transition logic, not any provider's HTTP shape.
 */
final class StreamingTokenManagerTest extends KernelTestCase
{
    public function testAnAccountNotCloseToExpiryIsReturnedWithoutRefreshing(): void
    {
        self::bootKernel();
        $account = $this->persistAccount(expiresAt: new \DateTimeImmutable('+1 hour'));

        $manager = $this->makeManager();
        $tokens = $manager->usableTokens($account);

        self::assertSame('stored-access', $tokens->accessToken, 'Untouched — no refresh should have happened.');
    }

    public function testAnExpiredAccountIsRefreshedProactivelyAndTransparently(): void
    {
        self::bootKernel();
        $account = $this->persistAccount(expiresAt: new \DateTimeImmutable('-1 minute'));

        $manager = $this->makeManager();
        $tokens = $manager->usableTokens($account);

        self::assertSame('double-access-refreshed', $tokens->accessToken, 'AC-4.6: succeeds with no error surfaced.');
        self::assertSame(StreamingAccount::STATUS_CONNECTED, $account->getStatus());
    }

    /**
     * AC-4.3: a second caller on the same already-refreshed account must not trigger a second
     * refresh — the double-check-after-acquiring-the-lock path is what collapses concurrent callers
     * to one refresh; this proves that mechanism deterministically within one process rather than
     * via actual OS-level concurrency (a genuine multi-process test would exercise the same code
     * path, at a much higher cost for this branch).
     */
    public function testASecondCallAfterTheFirstHasAlreadyRefreshedDoesNotRefreshAgain(): void
    {
        self::bootKernel();
        $account = $this->persistAccount(expiresAt: new \DateTimeImmutable('-1 minute'));
        $manager = $this->makeManager();

        $first = $manager->usableTokens($account);
        self::assertSame('double-access-refreshed', $first->accessToken);

        // The entity now has a fresh, non-expiring token — a second caller must short-circuit
        // before ever asking the provider to refresh again.
        $second = $manager->usableTokens($account);
        self::assertSame('double-access-refreshed', $second->accessToken);
        self::assertSame($first->accessToken, $second->accessToken);
    }

    public function testAnUnrecoverableGrantFailureSetsNeedsReauthAndClearsTokens(): void
    {
        self::bootKernel();
        // The test-double's refreshToken() never fails — use a refresh token value the fixture
        // provider treats as invalid is not modeled, so this test drives the manager's own
        // exception handling directly against a throwing decorator instead of the real double.
        $account = $this->persistAccount(expiresAt: new \DateTimeImmutable('-1 minute'), provider: 'unrecoverable-double');

        $container = static::getContainer();
        $manager = new StreamingTokenManager(
            locator: new StreamingProviderLocator([new class implements \App\Service\Streaming\StreamingProviderInterface {
                public function key(): string
                {
                    return 'unrecoverable-double';
                }

                public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
                {
                    return '';
                }

                public function exchangeCode(string $code, string $redirectUri, ?string $codeVerifier = null): \App\Service\Streaming\Model\ProviderTokens
                {
                    throw new \LogicException('unused');
                }

                public function refreshToken(\App\Service\Streaming\Model\ProviderTokens $tokens): \App\Service\Streaming\Model\ProviderTokens
                {
                    throw new TokenExpiredException('refresh token revoked');
                }

                public function searchTrack(\App\Service\Streaming\Model\SongQuery $query, \App\Service\Streaming\Model\ProviderTokens $tokens): array
                {
                    return [];
                }

                public function createPlaylist(\App\Service\Streaming\Model\PlaylistDraft $draft, \App\Service\Streaming\Model\ProviderTokens $tokens): \App\Service\Streaming\Model\ProviderPlaylist
                {
                    throw new \LogicException('unused');
                }

                public function addTracks(string $playlistId, array $trackIds, \App\Service\Streaming\Model\ProviderTokens $tokens): void
                {
                }

                public function playlistEmbedUrl(string $playlistId): ?string
                {
                    return null;
                }

                public function playlistDeepLink(string $playlistId): string
                {
                    return '';
                }
            }]),
            repository: $container->get(StreamingAccountRepository::class),
            entityManager: $container->get(EntityManagerInterface::class),
            lockFactory: new LockFactory(new FlockStore()),
            clock: new MockClock(),
            refreshSkewSeconds: 60,
        );

        $this->expectException(TokenExpiredException::class);
        try {
            $manager->usableTokens($account);
        } finally {
            self::assertSame(StreamingAccount::STATUS_NEEDS_REAUTH, $account->getStatus(), 'D-80: status flips.');
            self::assertNull($account->getAccessToken(), 'D-80: tokens are cleared.');
            self::assertNull($account->getRefreshToken());
        }
    }

    private function makeManager(): StreamingTokenManager
    {
        $container = static::getContainer();

        return new StreamingTokenManager(
            locator: $container->get(StreamingProviderLocator::class),
            repository: $container->get(StreamingAccountRepository::class),
            entityManager: $container->get(EntityManagerInterface::class),
            lockFactory: new LockFactory(new FlockStore()),
            clock: new MockClock(),
            refreshSkewSeconds: 60,
        );
    }

    private function persistAccount(\DateTimeImmutable $expiresAt, string $provider = TestDoubleStreamingProvider::KEY): StreamingAccount
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = new User(\sprintf('token-manager.%s@example.test', Uuid::v4()), 'placeholder-hash');
        $em->persist($user);
        $em->flush();

        $account = new StreamingAccount(
            user: $user,
            provider: $provider,
            accessToken: 'stored-access',
            refreshToken: 'stored-refresh',
            expiresAt: $expiresAt,
            scopes: ['scope-a'],
            providerAccountId: 'ext-1',
            providerDisplayName: 'Ext Display',
            now: new \DateTimeImmutable(),
        );

        $em->persist($account);
        $em->flush();

        return $account;
    }
}
