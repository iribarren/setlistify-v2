<?php

declare(strict_types=1);

namespace App\Tests\Functional\Streaming;

use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\StreamingAccountRepository;
use App\Service\Provider\ProviderAvailability;
use App\Service\Security\RateLimiterGuard;
use App\Service\Streaming\Link\LinkFlowService;
use App\Service\Streaming\Link\LinkResultStore;
use App\Service\Streaming\Link\PendingLinkStore;
use App\Service\Streaming\StreamingProviderLocator;
use App\Tests\Support\Streaming\TestDoubleStreamingProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * US-1, US-8: the full OAuth round trip through `LinkFlowService`, using
 * `App\Tests\Support\Streaming\TestDoubleStreamingProvider` (registered only in the `when@test`
 * services block) rather than the real Spotify adapter — this test is about the link/state/PKCE
 * lifecycle, not any one provider's HTTP shape.
 */
final class LinkFlowServiceTest extends KernelTestCase
{
    private const string PROVIDER = TestDoubleStreamingProvider::KEY;

    public function testFullRoundTripCreatesAnAccount(): void
    {
        self::bootKernel();
        $user = $this->persistUser();
        $service = $this->makeService();

        $authUrl = $service->start($user->getId() ?? 0, self::PROVIDER, 'web');
        self::assertStringContainsString('double.invalid/authorize', $authUrl);

        $state = $this->extractQueryParam($authUrl, 'state');
        $returnUrl = $service->completeCallback(self::PROVIDER, code: 'auth-code-1', state: $state, errorParam: null);

        self::assertStringStartsWith('https://web.test/account', $returnUrl);
        $ref = $this->extractQueryParam($returnUrl, 'ref');
        self::assertNotSame('', $ref);

        $result = $service->resolveResult($user->getId() ?? 0, $ref);
        self::assertNotNull($result);
        self::assertTrue($result->success);
        self::assertSame(self::PROVIDER, $result->provider);

        $account = $this->accountRepository()->findOneByUserAndProvider($user->getId() ?? 0, self::PROVIDER);
        self::assertInstanceOf(StreamingAccount::class, $account);
        self::assertSame(StreamingAccount::STATUS_CONNECTED, $account->getStatus());
    }

    public function testCompletingTwiceUpdatesTheSameRowRatherThanCreatingASecondOne(): void
    {
        self::bootKernel();
        $user = $this->persistUser();
        $service = $this->makeService();

        $state1 = $this->extractQueryParam($service->start($user->getId() ?? 0, self::PROVIDER, 'web'), 'state');
        $service->completeCallback(self::PROVIDER, 'code-a', $state1, null);

        $state2 = $this->extractQueryParam($service->start($user->getId() ?? 0, self::PROVIDER, 'web'), 'state');
        $service->completeCallback(self::PROVIDER, 'code-b', $state2, null);

        $accounts = $this->accountRepository()->findBy(['user' => $user, 'provider' => self::PROVIDER]);
        self::assertCount(1, $accounts, 'AC-1.5: (user, provider) stays a single row.');
    }

    public function testAReplayedStateIsRejected(): void
    {
        self::bootKernel();
        $user = $this->persistUser();
        $service = $this->makeService();

        $state = $this->extractQueryParam($service->start($user->getId() ?? 0, self::PROVIDER, 'web'), 'state');

        $firstReturn = $service->completeCallback(self::PROVIDER, 'code-1', $state, null);
        self::assertStringContainsString('ref=', $firstReturn);

        // AC-8.2: the same state, replayed, is rejected — a generic redirect with no new ref minted.
        $secondReturn = $service->completeCallback(self::PROVIDER, 'code-1', $state, null);
        self::assertSame('https://web.test/account', $secondReturn);
    }

    public function testAMissingOrUnknownStateIsRejectedGenerically(): void
    {
        self::bootKernel();
        $service = $this->makeService();

        $returnUrl = $service->completeCallback(self::PROVIDER, 'code-1', 'not-a-real-state', null);

        self::assertSame('https://web.test/account', $returnUrl, 'AC-8.3: no ref minted for an unresolvable state.');
    }

    public function testTwoUsersPendingLinksNeverCollide(): void
    {
        self::bootKernel();
        $userA = $this->persistUser();
        $userB = $this->persistUser();
        $service = $this->makeService();

        $stateA = $this->extractQueryParam($service->start($userA->getId() ?? 0, self::PROVIDER, 'web'), 'state');
        $stateB = $this->extractQueryParam($service->start($userB->getId() ?? 0, self::PROVIDER, 'web'), 'state');

        // Complete B's callback first, then A's — interleaved on purpose.
        $service->completeCallback(self::PROVIDER, 'code-b', $stateB, null);
        $service->completeCallback(self::PROVIDER, 'code-a', $stateA, null);

        $accountA = $this->accountRepository()->findOneByUserAndProvider($userA->getId() ?? 0, self::PROVIDER);
        $accountB = $this->accountRepository()->findOneByUserAndProvider($userB->getId() ?? 0, self::PROVIDER);

        self::assertInstanceOf(StreamingAccount::class, $accountA);
        self::assertInstanceOf(StreamingAccount::class, $accountB);
        self::assertNotSame($accountA->getId(), $accountB->getId());
        self::assertSame($userA->getId(), $accountA->getUser()->getId(), 'AC-8.4: state A never attaches to user B.');
        self::assertSame($userB->getId(), $accountB->getUser()->getId());
    }

    public function testProviderDenialWritesNoRecordButStillResolvesToAFailureResult(): void
    {
        self::bootKernel();
        $user = $this->persistUser();
        $service = $this->makeService();

        $state = $this->extractQueryParam($service->start($user->getId() ?? 0, self::PROVIDER, 'web'), 'state');
        $returnUrl = $service->completeCallback(self::PROVIDER, code: null, state: $state, errorParam: 'access_denied');

        $ref = $this->extractQueryParam($returnUrl, 'ref');
        $result = $service->resolveResult($user->getId() ?? 0, $ref);

        self::assertNotNull($result);
        self::assertFalse($result->success);

        self::assertNull($this->accountRepository()->findOneByUserAndProvider($user->getId() ?? 0, self::PROVIDER), 'AC-8.5: no record written.');
    }

    public function testALinkResultRefIsResolvableOnlyByTheUserItWasIssuedTo(): void
    {
        self::bootKernel();
        $user = $this->persistUser();
        $intruder = $this->persistUser();
        $service = $this->makeService();

        $state = $this->extractQueryParam($service->start($user->getId() ?? 0, self::PROVIDER, 'web'), 'state');
        $returnUrl = $service->completeCallback(self::PROVIDER, 'code-1', $state, null);
        $ref = $this->extractQueryParam($returnUrl, 'ref');

        self::assertNull($service->resolveResult($intruder->getId() ?? 0, $ref), 'AC-8.7: not resolvable by another user.');
    }

    /**
     * AC-4.2 (docs/specs/2026-08-22-backoffice-provider-configuration.md): a disabled provider is
     * refused before the rate limiter or the locator is touched — no `state` written to Redis for a
     * request that could never have worked.
     */
    public function testADisabledProviderRefusesStartBeforeWritingAnyPendingLinkState(): void
    {
        self::bootKernel();
        $user = $this->persistUser();
        $container = static::getContainer();

        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $redis->del($redis->keys('streaming:link:*'));

        $rateLimiterFactory = new RateLimiterFactory(
            ['id' => 'streaming_link_start_test', 'policy' => 'sliding_window', 'limit' => 1000, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        $service = new LinkFlowService(
            locator: $container->get(StreamingProviderLocator::class),
            providerAvailability: new class implements ProviderAvailability {
                public function isAvailable(string $providerKey): bool
                {
                    return false;
                }
            },
            pendingLinkStore: new PendingLinkStore($redis, ttlSeconds: 600),
            linkResultStore: new LinkResultStore($redis, ttlSeconds: 300),
            accountRepository: $this->accountRepository(),
            entityManager: $container->get(EntityManagerInterface::class),
            rateLimiterGuard: new RateLimiterGuard(new NullLogger()),
            streamingLinkStartLimiter: $rateLimiterFactory,
            clock: new MockClock(),
            redirectUrisByProvider: [self::PROVIDER => 'https://backend.test/streaming/'.self::PROVIDER.'/callback'],
            webReturnUrl: 'https://web.test/account',
            nativeReturnUrl: 'setlistify://account',
        );

        $this->expectException(\App\Service\Provider\ProviderDisabledException::class);
        try {
            $service->start($user->getId() ?? 0, self::PROVIDER, 'web');
        } finally {
            self::assertSame([], $redis->keys('streaming:link:*'), 'No pending-link state must be written for a disabled provider.');
        }
    }

    private function persistUser(): User
    {
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $user = new User(\sprintf('streaming.%s@example.test', Uuid::v4()), 'placeholder-hash');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function accountRepository(): StreamingAccountRepository
    {
        return static::getContainer()->get(StreamingAccountRepository::class);
    }

    private function makeService(): LinkFlowService
    {
        $container = static::getContainer();

        $redis = new \Redis();
        $redis->connect('redis', 6379);
        $redis->del($redis->keys('streaming:link:*'));

        $rateLimiterFactory = new RateLimiterFactory(
            ['id' => 'streaming_link_start_test', 'policy' => 'sliding_window', 'limit' => 1000, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new LinkFlowService(
            locator: $container->get(StreamingProviderLocator::class),
            providerAvailability: $this->alwaysAvailable(),
            pendingLinkStore: new PendingLinkStore($redis, ttlSeconds: 600),
            linkResultStore: new LinkResultStore($redis, ttlSeconds: 300),
            accountRepository: $this->accountRepository(),
            entityManager: $container->get(EntityManagerInterface::class),
            rateLimiterGuard: new RateLimiterGuard(new NullLogger()),
            streamingLinkStartLimiter: $rateLimiterFactory,
            clock: new MockClock(),
            redirectUrisByProvider: [self::PROVIDER => 'https://backend.test/streaming/'.self::PROVIDER.'/callback'],
            webReturnUrl: 'https://web.test/account',
            nativeReturnUrl: 'setlistify://account',
        );
    }

    /** This suite exercises the link/state/PKCE lifecycle, not provider availability (covered separately). */
    private function alwaysAvailable(): ProviderAvailability
    {
        return new class implements ProviderAvailability {
            public function isAvailable(string $providerKey): bool
            {
                return true;
            }
        };
    }

    private function extractQueryParam(string $url, string $name): string
    {
        $query = (string) parse_url($url, \PHP_URL_QUERY);
        parse_str($query, $params);

        return \is_string($params[$name] ?? null) ? $params[$name] : '';
    }
}
