<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The identity root of the application (docs/architecture.md §10). Never exposed as a writable
 * API Platform resource (D-22, AC-10.2) — every public payload is a DTO in `App\ApiResource`,
 * bound through a state provider/processor in `App\State`.
 *
 * `$roles` is populated exactly once, server-side, at registration (`["ROLE_USER"]` — AC-1.6,
 * AC-10.1). Nothing in this class ever reads `$roles` from request input; the only other writer is
 * the `app:admin:create` console command (AC-10.4).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /**
     * Always trimmed and lowercased before persistence (AC-1.3) — see
     * `App\Service\Security\EmailNormalizer`. The unique index enforces the database half of the
     * uniqueness guarantee; `App\Validator\UniqueEmail` enforces the request-time half.
     */
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $email;

    /** The bcrypt/argon hash, never the plaintext. Excluded from every serialization group (AC-11.1). */
    #[ORM\Column(type: 'string')]
    private string $password;

    /** @var list<string> Always `["ROLE_USER"]` at creation. See class docblock. */
    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /**
     * `null` means not entitled (D-257, docs/specs/2026-08-27-instant-setlist-refresh.md). NOT
     * `$roles` — this stays a nullable grant timestamp, mirroring `$emailVerifiedAt`'s shape, so
     * `$roles` remains literally "populated exactly once, server-side, at registration". Written
     * only by `App\Controller\Admin\UserCrudController`'s grant/revoke action; read only by
     * `App\Security\Voter\InstantRefreshVoter` (AC-7.3, statically enforced).
     */
    #[ORM\Column(name: 'instant_refresh_granted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $instantRefreshGrantedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * The TOTP secret, encrypted at rest (AC-5.3, docs/specs/2026-08-21-backoffice-foundation.md)
     * via `App\Security\Admin\TotpSecretEncryptor` — never plaintext in the database, never read by
     * this class itself (decryption happens only in `App\Security\Admin\AdminUser`, which the admin
     * firewall's provider constructs). Excluded from every serialization group and every EasyAdmin
     * field list (D-46) — nothing here is public API surface.
     */
    #[ORM\Column(name: 'totp_secret_cipher', type: 'text', nullable: true)]
    private ?string $totpSecretCipher = null;

    /**
     * Ten single-use backup codes (AC-5.4), hashed with the same auto-hasher configuration as
     * `$password` — never plaintext, never logged. Consumption removes an entry (D-49's console-only
     * reset regenerates the whole set).
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'backup_codes_hashed', type: 'json')]
    private array $backupCodesHashed = [];

    /**
     * NOT a mapped column — a transient, admin-list-only aggregate (AC-6.1, AC-6.2). Populated by
     * `App\Controller\Admin\UserCrudController::createIndexQueryBuilder()`'s single `COUNT`
     * subquery, aliased `AS HIDDEN concertCount` so Doctrine's hydrator assigns it onto this
     * property without treating the row as a mixed entity/scalar result. Never persisted, never
     * touched outside the admin list query.
     */
    private int $concertCount = 0;

    public function __construct(string $email, string $hashedPassword)
    {
        $this->email = $email;
        $this->password = $hashedPassword;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * UserInterface: the unique, human-readable identifier used by the security system.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    /**
     * Read by `lexik_jwt_authentication`'s `user_id_claim: sub` config (via property accessor) to
     * populate the JWT's `sub` claim with the numeric user id rather than the email — AC-2.3
     * forbids an email in the token. See `App\Service\Security\UserIdProvider`, which loads the
     * user back by this same value when a request presents the token.
     */
    public function getSub(): int
    {
        return $this->id ?? throw new \LogicException('User has no id yet — not persisted.');
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
        $this->touch();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * Server-side only — never called from a request-bound DTO. See class docblock and AC-10.1.
     *
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
        $this->touch();
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function markEmailVerified(\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt = $at;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Admin-only (D-44, docs/specs/2026-08-21-backoffice-foundation.md) — suspend/unsuspend, called
     * exclusively from `App\Controller\Admin\UserCrudController`. No public API input ever reaches
     * this: registration always creates an active user, and nothing in `App\ApiResource` exposes an
     * `isActive` field.
     */
    public function setActive(bool $active): void
    {
        $this->isActive = $active;
        $this->touch();
    }

    public function getInstantRefreshGrantedAt(): ?\DateTimeImmutable
    {
        return $this->instantRefreshGrantedAt;
    }

    /** Admin-only (D-257) — called exclusively from `App\Controller\Admin\UserCrudController`. */
    public function grantInstantRefresh(\DateTimeImmutable $at): void
    {
        $this->instantRefreshGrantedAt = $at;
        $this->touch();
    }

    /** Admin-only (D-257) — called exclusively from `App\Controller\Admin\UserCrudController`. */
    public function revokeInstantRefresh(): void
    {
        $this->instantRefreshGrantedAt = null;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTotpSecretCipher(): ?string
    {
        return $this->totpSecretCipher;
    }

    /** Server-side only, called from App\Security\Admin\AdminUser / the enrollment controller. */
    public function setTotpSecretCipher(?string $cipher): void
    {
        $this->totpSecretCipher = $cipher;
        $this->touch();
    }

    /** @return list<string> */
    public function getBackupCodesHashed(): array
    {
        return $this->backupCodesHashed;
    }

    /** @param list<string> $hashedCodes */
    public function setBackupCodesHashed(array $hashedCodes): void
    {
        $this->backupCodesHashed = $hashedCodes;
        $this->touch();
    }

    /** See {@see self::$concertCount} — only meaningful after the admin list query. */
    public function getConcertCount(): int
    {
        return $this->concertCount;
    }

    /** No sensitive temporary data is ever stored on the user (e.g. plain password). Nothing to erase. */
    public function eraseCredentials(): void
    {
    }
}
