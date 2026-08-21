<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Concert;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-27's second gate: `App\Security\ConcertOwnerExtension` already filters every query to the
 * current owner, so in practice this voter is never the thing that denies a real request — a
 * cross-owner item is never even loaded. It exists so a future code path that reaches a `Concert`
 * outside that filtered query (a subresource, an admin tool, a bug) still fails closed instead of
 * open. `App\State\Provider\ConcertItemProvider` and the update/delete processors call
 * `Security::isGranted()` with this attribute on every entity they load, belt-and-braces (AC-7.5).
 *
 * @extends Voter<string, Concert>
 */
final class ConcertVoter extends Voter
{
    public const string VIEW = 'CONCERT_VIEW';
    public const string EDIT = 'CONCERT_EDIT';
    public const string DELETE = 'CONCERT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true) && $subject instanceof Concert;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        // $subject is already narrowed to Concert here via the @extends Voter<string, Concert>
        // template on the class docblock — supports() is what actually enforces it at runtime.
        $user = $token->getUser();

        return $user instanceof User && $user->getId() === $subject->getOwner()->getId();
    }
}
