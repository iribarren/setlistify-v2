<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The single write path for {@see AuditLogEntry} (AC-12.6, D-43) — no controller or service ever
 * constructs the entity directly, so "did we audit this?" is answerable by looking at this class's
 * callers.
 *
 * `oldValue`/`newValue` are taken as-is: it is the **caller's** responsibility to pass
 * {@see self::digest()} output rather than a plaintext value for any field classified as personal
 * data (D-43) — a boolean flip like `isActive` is not personal data and may be passed literally.
 */
final readonly class AuditLogger
{
    public function __construct(
        private AuditLogEntryRepository $repository,
        private RequestStack $requestStack,
        private ClockInterface $clock,
        private string $digestKey,
    ) {
    }

    public function log(
        User $actor,
        string $action,
        string $subjectType,
        int|string $subjectId,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        $request = $this->requestStack->getCurrentRequest();

        $entry = new AuditLogEntry(
            occurredAt: \DateTimeImmutable::createFromInterface($this->clock->now()),
            actorId: $actor->getId() ?? 0,
            actorLabel: $this->digest($actor->getEmail()),
            action: $action,
            subjectType: $subjectType,
            subjectId: (string) $subjectId,
            field: $field,
            oldValue: $oldValue,
            newValue: $newValue,
            ipAddress: $request?->getClientIp() ?? '0.0.0.0',
            userAgent: $request?->headers->get('User-Agent'),
        );

        $this->repository->save($entry);
    }

    /**
     * A non-reversible, keyed digest of a personal-data value (D-43) — used both for `actorLabel`
     * and for any `oldValue`/`newValue` that would otherwise carry plaintext personal data (e.g. an
     * email). Truncated to 32 hex characters: enough to correlate "same value" without being a
     * usable identifier on its own.
     */
    public function digest(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->digestKey), 0, 32);
    }
}
