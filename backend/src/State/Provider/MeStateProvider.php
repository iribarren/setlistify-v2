<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * `GET /api/me` (AC-8.1). The `security: is_granted("IS_AUTHENTICATED_FULLY")` expression on the
 * resource operation already rejects an unauthenticated request with 401 before this runs; the
 * check here is a defensive second line, not the enforcement point.
 *
 * @implements ProviderInterface<Me>
 */
final readonly class MeStateProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return new Me(
            id: $user->getSub(),
            email: $user->getEmail(),
            emailVerified: $user->isEmailVerified(),
            roles: $user->getRoles(),
            createdAt: $user->getCreatedAt(),
        );
    }
}
