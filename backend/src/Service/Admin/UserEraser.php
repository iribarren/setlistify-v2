<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AC-7.4/D-45: a hard delete of the user, run in one transaction. `Concert` (and its `ConcertBand`
 * lineup rows), `RefreshToken`, `PasswordResetToken` and `EmailVerificationToken` all declare
 * `onDelete: 'CASCADE'` on their `user`/`owner` foreign key already (see those entities) — deleting
 * the `users` row cascades to every one of them at the database level, which is the "explicit
 * cascade, not Doctrine's in-memory one" D-45 asks for. `Band` and `Venue` are never referenced by
 * a foreign key to `users` at all, so they are structurally unreachable by this cascade — they
 * survive by construction, not by care taken here.
 *
 * The {@see AuditLogger} entry is written in the *same* transaction and holds no foreign key to
 * `users` (D-43) — it survives the delete it describes (AC-7.6).
 */
final readonly class UserEraser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
    ) {
    }

    public function erase(User $subject, User $actor): void
    {
        $subjectId = $subject->getId() ?? throw new \LogicException('Cannot erase a user that was never persisted.');

        $this->entityManager->wrapInTransaction(function () use ($subject, $actor, $subjectId): void {
            $this->auditLogger->log(
                actor: $actor,
                action: 'delete_user',
                subjectType: 'User',
                subjectId: $subjectId,
            );

            $this->entityManager->remove($subject);
            $this->entityManager->flush();
        });
    }
}
