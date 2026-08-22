<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StreamingAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's link to one streaming provider (`docs/architecture.md` §10, US-1 through US-6). Provider
 * identity is `$provider` — the port's `key()` string, never a class name (D-72) — so this entity
 * itself is provider-agnostic and does not belong to any adapter directory.
 *
 * `$accessToken`/`$refreshToken` are the `encrypted_string` Doctrine type (D-78): every read/write
 * through this entity is transparently encrypted/decrypted, so there is no unencrypted code path.
 * `(user, provider)` is unique (AC-1.5) — completing the link flow twice updates this same row via
 * {@see self::relink()} rather than creating a second one.
 *
 * `$status` follows D-80: an unrecoverable refresh failure moves `connected` -> `needs_reauth` and
 * clears the token columns via {@see self::markNeedsReauth()}, but the row itself survives so the
 * UI can offer "Reconnect" instead of a blank slate. A successful (re)link always returns the
 * account to `connected` (AC-5.5).
 */
#[ORM\Entity(repositoryClass: StreamingAccountRepository::class)]
#[ORM\Table(name: 'streaming_accounts')]
#[ORM\UniqueConstraint(name: 'uniq_streaming_accounts_user_provider', columns: ['user_id', 'provider'])]
#[ORM\Index(name: 'idx_streaming_accounts_status', columns: ['status'])]
class StreamingAccount
{
    public const string STATUS_CONNECTED = 'connected';
    public const string STATUS_NEEDS_REAUTH = 'needs_reauth';
    public const string STATUS_REVOKED_BY_USER = 'revoked_by_user';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The port's `key()`, e.g. `'spotify'` — never a class name (D-72). */
    #[ORM\Column(type: 'string', length: 32)]
    private string $provider;

    #[ORM\Column(name: 'access_token', type: 'encrypted_string', nullable: true)]
    private ?string $accessToken;

    #[ORM\Column(name: 'refresh_token', type: 'encrypted_string', nullable: true)]
    private ?string $refreshToken;

    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    /** @var list<string> granted scopes, as returned by the provider — not as requested (D-88). */
    #[ORM\Column(type: 'json')]
    private array $scopes;

    #[ORM\Column(name: 'provider_account_id', type: 'string', length: 128)]
    private string $providerAccountId;

    #[ORM\Column(name: 'provider_display_name', type: 'string', length: 200, nullable: true)]
    private ?string $providerDisplayName;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(name: 'linked_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @param list<string> $scopes */
    public function __construct(
        User $user,
        string $provider,
        string $accessToken,
        ?string $refreshToken,
        ?\DateTimeImmutable $expiresAt,
        array $scopes,
        string $providerAccountId,
        ?string $providerDisplayName,
        \DateTimeImmutable $now,
    ) {
        $this->user = $user;
        $this->provider = $provider;
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt;
        $this->scopes = $scopes;
        $this->providerAccountId = $providerAccountId;
        $this->providerDisplayName = $providerDisplayName;
        $this->status = self::STATUS_CONNECTED;
        $this->linkedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getProviderAccountId(): string
    {
        return $this->providerAccountId;
    }

    public function getProviderDisplayName(): ?string
    {
        return $this->providerDisplayName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** AC-1.5: completing the link flow again for this (user, provider) updates this same row. */
    public function relink(
        string $accessToken,
        ?string $refreshToken,
        ?\DateTimeImmutable $expiresAt,
        array $scopes,
        string $providerAccountId,
        ?string $providerDisplayName,
        \DateTimeImmutable $now,
    ): void {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt;
        $this->scopes = $scopes;
        $this->providerAccountId = $providerAccountId;
        $this->providerDisplayName = $providerDisplayName;
        $this->status = self::STATUS_CONNECTED;
        $this->updatedAt = $now;
    }

    /** D-79/AC-4.5: a proactive refresh succeeded — write the new tokens back. */
    public function applyRefreshedTokens(string $accessToken, ?string $refreshToken, \DateTimeImmutable $expiresAt, \DateTimeImmutable $now): void
    {
        $this->accessToken = $accessToken;
        // AC-4.4: a refresh response that omits a new refresh token keeps the existing one.
        if (null !== $refreshToken) {
            $this->refreshToken = $refreshToken;
        }
        $this->expiresAt = $expiresAt;
        $this->status = self::STATUS_CONNECTED;
        $this->updatedAt = $now;
    }

    /** D-80: an unrecoverable grant failure. The row survives; the tokens do not. */
    public function markNeedsReauth(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_NEEDS_REAUTH;
        $this->accessToken = null;
        $this->refreshToken = null;
        $this->expiresAt = null;
        $this->updatedAt = $now;
    }

    public function isExpiringWithin(int $skewSeconds, \DateTimeImmutable $now): bool
    {
        if (null === $this->expiresAt) {
            return true;
        }

        return $this->expiresAt <= $now->modify(\sprintf('+%d seconds', $skewSeconds));
    }
}
