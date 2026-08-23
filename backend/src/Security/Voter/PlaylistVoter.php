<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Playlist;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * D-157's second gate, copying `ConcertVoter`'s shape: `PlaylistOwnerExtension` already filters
 * every query to the current owner, so this voter is the belt to that braces.
 *
 * @extends Voter<string, Playlist>
 */
final class PlaylistVoter extends Voter
{
    public const string VIEW = 'PLAYLIST_VIEW';
    public const string DELETE = 'PLAYLIST_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::DELETE], true) && $subject instanceof Playlist;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->getId() === $subject->getOwner()->getId();
    }
}
