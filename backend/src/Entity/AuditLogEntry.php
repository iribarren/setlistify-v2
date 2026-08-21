<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An append-only record of one administrative write (docs/specs/2026-08-21-backoffice-foundation.md
 * US-12, AC-12.1). Never an API Platform resource (AC-11.2) — it exists only for the backoffice.
 *
 * `$actorId` is a plain integer with **no foreign key** to `users`, and `$actorLabel`/`$oldValue`/
 * `$newValue` never carry plaintext personal data — see `App\Service\Admin\AuditLogger` (D-43). This
 * is deliberate: the entry must survive the deletion of the user it describes (AC-12.7, AC-7.6)
 * without resurrecting their personal data.
 *
 * Updates and deletes are rejected by `App\EventSubscriber\AuditLogAppendOnlySubscriber` (AC-12.4).
 */
#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entries')]
#[ORM\Index(name: 'idx_audit_log_entries_occurred_at', columns: ['occurred_at'])]
#[ORM\Index(name: 'idx_audit_log_entries_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_audit_log_entries_action', columns: ['action'])]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** Plain integer, no FK — the actor's account may itself be deleted later. */
    #[ORM\Column(name: 'actor_id', type: 'integer')]
    private int $actorId;

    /** Non-reversible short digest of the actor's identity (D-43), never a plaintext email. */
    #[ORM\Column(name: 'actor_label', type: 'string', length: 32)]
    private string $actorLabel;

    #[ORM\Column(type: 'string', length: 64)]
    private string $action;

    #[ORM\Column(name: 'subject_type', type: 'string', length: 64)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id', type: 'string', length: 64)]
    private string $subjectId;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $field;

    /** Never plaintext personal data — a keyed digest for personal-data fields (D-43). */
    #[ORM\Column(name: 'old_value', type: 'text', nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(name: 'new_value', type: 'text', nullable: true)]
    private ?string $newValue;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45)]
    private string $ipAddress;

    /** Truncated (AC-12.1) — never trusted as anything but a display hint. */
    #[ORM\Column(name: 'user_agent', type: 'string', length: 255, nullable: true)]
    private ?string $userAgent;

    public function __construct(
        \DateTimeImmutable $occurredAt,
        int $actorId,
        string $actorLabel,
        string $action,
        string $subjectType,
        string $subjectId,
        ?string $field,
        ?string $oldValue,
        ?string $newValue,
        string $ipAddress,
        ?string $userAgent,
    ) {
        $this->occurredAt = $occurredAt;
        $this->actorId = $actorId;
        $this->actorLabel = $actorLabel;
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->field = $field;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->ipAddress = $ipAddress;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): int
    {
        return $this->actorId;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
