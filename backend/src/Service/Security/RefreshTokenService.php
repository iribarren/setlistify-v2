<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Owns the whole refresh-token lifecycle (D-21): issuance, hashed storage, rotation, families and
 * reuse detection (AC-4.1–AC-4.4).
 *
 * **Grace window (R-3).** A dropped response or two tabs refreshing at once both look, from the
 * server's point of view, like the same already-rotated token being presented twice. Killing the
 * family on the very first repeat would log everyone out for a network hiccup. So a token that was
 * rotated less than {@see self::GRACE_WINDOW_SECONDS} ago is treated as a benign duplicate: instead
 * of killing the family, we rotate the family's current tip again and hand back a fresh, usable
 * pair. Only a repeat *older* than the grace window — a genuinely stale token being replayed — kills
 * the family.
 */
final readonly class RefreshTokenService
{
    /** Seconds. Long enough to absorb a retried request or a burst of parallel tabs; short enough
     *  that it does not meaningfully widen the reuse-detection window (R-3). */
    private const int GRACE_WINDOW_SECONDS = 10;

    public function __construct(
        private RefreshTokenRepository $repository,
        private string $refreshTokenTtl,
        private LoggerInterface $securityLogger,
    ) {
    }

    /** Issues a brand-new token family — used at login. */
    public function issueForUser(User $user): IssuedRefreshToken
    {
        return $this->mint($user, Uuid::v4()->toRfc4122());
    }

    /**
     * Exchanges a presented plaintext refresh token for a new pair, rotating it. Throws
     * {@see RefreshTokenInvalidException} for unknown/expired/revoked tokens and for replay
     * detected outside the grace window.
     */
    public function rotate(string $plaintextToken): IssuedRefreshToken
    {
        $token = $this->repository->findOneByTokenHash($this->hash($plaintextToken));
        $now = new \DateTimeImmutable();

        if (null === $token || $token->isExpired($now) || null !== $token->getRevokedAt()) {
            throw new RefreshTokenInvalidException();
        }

        if (null !== $token->getRotatedAt()) {
            return $this->handleAlreadyRotated($token, $now);
        }

        $token->markRotated($now);
        $this->repository->save($token);

        return $this->mint($token->getUser(), $token->getFamily());
    }

    /** Revokes every token in the family the presented token belongs to (AC-5.1). Never throws. */
    public function revokeFamilyForToken(string $plaintextToken): void
    {
        $token = $this->repository->findOneByTokenHash($this->hash($plaintextToken));
        if (null === $token) {
            return;
        }

        $this->repository->revokeFamily($token->getFamily(), new \DateTimeImmutable());
    }

    public function revokeAllForUser(User $user): void
    {
        $this->repository->revokeAllForUser($user, new \DateTimeImmutable());
    }

    private function handleAlreadyRotated(RefreshToken $token, \DateTimeImmutable $now): IssuedRefreshToken
    {
        $elapsed = $now->getTimestamp() - $token->getRotatedAt()?->getTimestamp();

        if ($elapsed <= self::GRACE_WINDOW_SECONDS) {
            $tip = $this->repository->findActiveTipOfFamily($token->getFamily());
            if (null !== $tip) {
                $tip->markRotated($now);
                $this->repository->save($tip);

                return $this->mint($tip->getUser(), $tip->getFamily());
            }
            // Fall through: no usable tip left, so treat as reuse below.
        }

        $this->securityLogger->warning('Refresh token reuse detected — family revoked', [
            'user_id' => $token->getUser()->getId(),
            'family' => $token->getFamily(),
        ]);
        $this->repository->revokeFamily($token->getFamily(), $now);

        throw new RefreshTokenInvalidException();
    }

    private function mint(User $user, string $family): IssuedRefreshToken
    {
        $plaintext = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->add(new \DateInterval("PT{$this->refreshTokenTtl}S"));

        $entity = new RefreshToken($user, $this->hash($plaintext), $family, $expiresAt);
        $this->repository->save($entity);

        return new IssuedRefreshToken($plaintext, $entity);
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
