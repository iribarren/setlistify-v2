<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The `IS_EMAIL_VERIFIED` security attribute (D-19, AC-7.4). Behind `AUTH_REQUIRE_VERIFIED_EMAIL`
 * (default `false`): with the flag off, every user passes regardless of verification state, so
 * flipping it on later is a config change, not a new code path. `App\State\Processor\LoginProcessor`
 * is the only caller today (AC-7.5) — it must fail with the *same* generic 401 as a wrong password
 * when this voter denies, so enabling the flag never becomes an enumeration oracle.
 *
 * @extends Voter<string, User>
 */
final class EmailVerifiedVoter extends Voter
{
    public const string ATTRIBUTE = 'IS_EMAIL_VERIFIED';

    public function __construct(
        private readonly bool $requireVerifiedEmail,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$this->requireVerifiedEmail) {
            return true;
        }

        return $subject->isEmailVerified();
    }
}
