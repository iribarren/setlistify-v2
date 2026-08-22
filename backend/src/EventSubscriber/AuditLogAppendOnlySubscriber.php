<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\AuditLogEntry;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Enforces that {@see AuditLogEntry} is append-only (AC-12.4, US-12): a trail that could be edited
 * or deleted through the same ORM that wrote it would not be trustworthy after an incident. This is
 * the second net after "nothing in the codebase calls `update`/`remove` on it" — a Doctrine event
 * subscriber rejects the operation outright regardless of how it was triggered.
 *
 * Implements {@see EventSubscriber} (not just a plain class) so DoctrineBundle's autoconfiguration
 * tags and registers it automatically — no explicit `tags:` entry needed in `services.yaml`.
 */
final class AuditLogAppendOnlySubscriber implements EventSubscriber
{
    /** @return list<string> */
    public function getSubscribedEvents(): array
    {
        return [Events::preUpdate, Events::preRemove];
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->rejectIfAuditLogEntry($args->getObject(), 'updated');
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->rejectIfAuditLogEntry($args->getObject(), 'deleted');
    }

    private function rejectIfAuditLogEntry(object $entity, string $verb): void
    {
        if ($entity instanceof AuditLogEntry) {
            throw new \LogicException(\sprintf('AuditLogEntry is append-only and cannot be %s.', $verb));
        }
    }
}
