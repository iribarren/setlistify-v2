<?php

declare(strict_types=1);

namespace App\Security\Admin;

use Scheb\TwoFactorBundle\Model\PersisterInterface;

/**
 * scheb/2fa's default persister (`scheb_two_factor.persister.doctrine`) calls
 * `EntityManager::persist()` on whatever object implements its 2FA interfaces — here, that's
 * {@see AdminUser}, which is a plain model object, not a mapped Doctrine entity, and would throw a
 * mapping exception. `AdminUser::invalidateBackupCode()` already persists+flushes the *wrapped*
 * `User` entity itself, so this persister is intentionally a no-op.
 */
final class NullTwoFactorPersister implements PersisterInterface
{
    public function persist(object $user): void
    {
    }
}
