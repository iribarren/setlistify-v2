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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

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

    /** No sensitive temporary data is ever stored on the user (e.g. plain password). Nothing to erase. */
    public function eraseCredentials(): void
    {
    }
}
