<?php

declare(strict_types=1);

namespace App\Service\Streaming\Link;

/**
 * D-76: pending-link `state` records, in Redis, single-use, short-TTL. Redis is already a hard
 * dependency with a fail-closed posture elsewhere (`App\Service\Security\RateLimiterGuard`) — an
 * unreachable Redis here means linking is unavailable, which is correct: it is vastly better than
 * linking that silently skips replay protection.
 *
 * {@see self::consume()} is atomic (a Lua script: GET then DEL in one round trip) so two concurrent
 * callers racing to consume the same `state` cannot both succeed — AC-8.2's "deleted on first use"
 * is a real guarantee, not a best-effort one.
 */
final readonly class PendingLinkStore
{
    private const string KEY_PREFIX = 'streaming:link:state:';

    private const string CONSUME_SCRIPT = <<<'LUA'
        local v = redis.call('GET', KEYS[1])
        if v then
            redis.call('DEL', KEYS[1])
        end
        return v
        LUA;

    public function __construct(
        private \Redis $redis,
        private int $ttlSeconds,
    ) {
    }

    /** @return string the newly generated `state` */
    public function create(int $userId, string $provider, string $platform, string $codeVerifier): string
    {
        $state = bin2hex(random_bytes(32));

        $payload = json_encode([
            'userId' => $userId,
            'provider' => $provider,
            'platform' => $platform,
            'codeVerifier' => $codeVerifier,
        ], \JSON_THROW_ON_ERROR);

        $this->redis->set(self::KEY_PREFIX.$state, $payload, ['ex' => $this->ttlSeconds]);

        return $state;
    }

    /** Null for a missing, expired or already-consumed `state` (AC-8.2, AC-8.3). */
    public function consume(string $state): ?PendingLink
    {
        if ('' === $state) {
            return null;
        }

        /** @var string|false|null $raw */
        $raw = $this->redis->eval(self::CONSUME_SCRIPT, [self::KEY_PREFIX.$state], 1);

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !\is_int($data['userId'] ?? null) || !\is_string($data['provider'] ?? null)
            || !\is_string($data['platform'] ?? null) || !\is_string($data['codeVerifier'] ?? null)) {
            return null;
        }

        return new PendingLink(
            userId: $data['userId'],
            provider: $data['provider'],
            platform: $data['platform'],
            codeVerifier: $data['codeVerifier'],
        );
    }
}
