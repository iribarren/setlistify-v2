<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\PlaylistGenerationJob;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-157's second gate, copying `ConcertVoter`'s shape: `PlaylistGenerationJobOwnerExtension` already
 * filters every query to the current owner, so this voter is the belt to that braces.
 *
 * @extends Voter<string, PlaylistGenerationJob>
 */
final class PlaylistGenerationJobVoter extends Voter
{
    public const string VIEW = 'PLAYLIST_GENERATION_JOB_VIEW';
    public const string MANAGE = 'PLAYLIST_GENERATION_JOB_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true) && $subject instanceof PlaylistGenerationJob;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->getId() === $subject->getOwner()->getId();
    }
}
