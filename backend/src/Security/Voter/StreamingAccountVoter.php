<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\StreamingAccount;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-77's second gate, mirroring `App\Security\Voter\ConcertVoter`: `App\Security\
 * StreamingAccountOwnerExtension` already filters every query to the current owner, so this voter
 * is never the thing that denies a real request in practice — it exists so a future code path that
 * reaches a `StreamingAccount` outside that filtered query still fails closed instead of open.
 *
 * @extends Voter<string, StreamingAccount>
 */
final class StreamingAccountVoter extends Voter
{
    public const string VIEW = 'STREAMING_ACCOUNT_VIEW';
    public const string DELETE = 'STREAMING_ACCOUNT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::DELETE], true) && $subject instanceof StreamingAccount;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->getId() === $subject->getUser()->getId();
    }
}
