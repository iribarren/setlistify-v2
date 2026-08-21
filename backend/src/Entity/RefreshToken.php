<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A rotating, family-scoped refresh token (D-21). Only the SHA-256 hash of the plaintext is ever
 * persisted (AC-4.2) — the plaintext exists solely in the login/refresh response and the httpOnly
 * cookie, never in this entity, never logged.
 *
 * Rotation model: every successful `/api/token/refresh` marks the presented row `rotatedAt` and
 * inserts a new row carrying the same `family`. Presenting a token that is already rotated or
 * revoked is treated as reuse — see `App\Service\Security\RefreshTokenService` for the family-kill
 * and grace-window logic (R-3).
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\Index(name: 'idx_refresh_tokens_family', columns: ['family'])]
#[ORM\Index(name: 'idx_refresh_tokens_expires_at', columns: ['expires_at'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 hex digest of the plaintext token. Never the plaintext itself. */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    /** Shared by every token descended from the same login, so reuse can kill the whole chain. */
    #[ORM\Column(type: 'string', length: 36)]
    private string $family;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $tokenHash, string $family, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->family = $family;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function markRotated(\DateTimeImmutable $at): void
    {
        $this->rotatedAt = $at;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt = $at;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->rotatedAt && null === $this->revokedAt && !$this->isExpired($now);
    }
}
