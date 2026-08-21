<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads a {@see User} by numeric id rather than by email — the counterpart to
 * `lexik_jwt_authentication`'s `user_id_claim: sub` config and {@see User::getSub()}. Used only by
 * the `api` firewall (`config/packages/security.yaml`) to resolve the user for an incoming bearer
 * JWT; login itself looks the user up by email directly (`App\State\Processor\LoginProcessor`).
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserIdProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!ctype_digit($identifier)) {
            throw new UserNotFoundException();
        }

        $user = $this->userRepository->find((int) $identifier);

        if (null === $user) {
            throw new UserNotFoundException();
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier((string) $user->getId());
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
