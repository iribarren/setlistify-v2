<?php

declare(strict_types=1);

namespace App\Service\Streaming\Link;

/**
 * The one-time, opaque reference the browser/native return leg carries (AC-1.7, AC-1.8) — never a
 * token, code or verifier, just enough for the client's authenticated read to resolve a
 * success/failure state. Single-use (same atomic GET+DEL pattern as `PendingLinkStore`) and
 * resolvable only by the user it was issued to (AC-8.7) — {@see self::consume()} takes the
 * requesting user's id and returns null on any mismatch, exactly like a missing/expired reference,
 * so a wrong-user attempt reveals nothing.
 */
final readonly class LinkResultStore
{
    private const string KEY_PREFIX = 'streaming:link:result:';

    private const string CONSUME_SCRIPT = <<<'LUA'
        local v = redis.call('GET', KEYS[1])
        if v then
            redis.call('DEL', KEYS[1])
        end
        return v
        LUA;

    public function __construct(
        private \Redis $redis,
        private int $ttlSeconds = 300,
    ) {
    }

    public function create(int $userId, string $provider, bool $success, ?string $reason = null): string
    {
        $ref = bin2hex(random_bytes(24));

        $payload = json_encode([
            'userId' => $userId,
            'provider' => $provider,
            'success' => $success,
            'reason' => $reason,
        ], \JSON_THROW_ON_ERROR);

        $this->redis->set(self::KEY_PREFIX.$ref, $payload, ['ex' => $this->ttlSeconds]);

        return $ref;
    }

    public function consume(string $ref, int $requestingUserId): ?LinkResult
    {
        if ('' === $ref) {
            return null;
        }

        /** @var string|false|null $raw */
        $raw = $this->redis->eval(self::CONSUME_SCRIPT, [self::KEY_PREFIX.$ref], 1);

        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            $data = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !\is_int($data['userId'] ?? null) || !\is_string($data['provider'] ?? null) || !\is_bool($data['success'] ?? null)) {
            return null;
        }

        // AC-8.7: resolvable only by the user it was issued to — a mismatch is indistinguishable
        // from "no such reference".
        if ($data['userId'] !== $requestingUserId) {
            return null;
        }

        $reason = \is_string($data['reason'] ?? null) ? $data['reason'] : null;

        return new LinkResult(provider: $data['provider'], success: $data['success'], reason: $reason);
    }
}
