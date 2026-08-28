<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The `CAN_REFRESH_SETLIST_NOW` attribute (docs/specs/2026-08-27-instant-setlist-refresh.md, D-258)
 * — copies `EmailVerifiedVoter`'s state-flag shape, not `ConcertVoter`'s ownership shape: one
 * boolean question over a `User` subject, no ownership semantics.
 *
 * The **only** place `User::$instantRefreshGrantedAt` is read (AC-7.3, statically enforced by
 * `App\Tests\Unit\Security\Voter\InstantRefreshVoterOnlyReaderTest`) — so migrating this capability
 * to prompt 22's `UserEntitlement`/`EntitlementPlan` is a rewrite of this method body and a dropped
 * column, not a hunt through every processor/handler/controller (D-267).
 *
 * @extends Voter<string, User>
 */
final class InstantRefreshVoter extends Voter
{
    public const string ATTRIBUTE = 'CAN_REFRESH_SETLIST_NOW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return null !== $subject->getInstantRefreshGrantedAt();
    }
}
