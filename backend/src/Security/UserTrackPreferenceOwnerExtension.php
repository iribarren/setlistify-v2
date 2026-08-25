<?php

declare(strict_types=1);

namespace App\Security;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use App\Entity\UserTrackPreference;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * A copy of `ConcertOwnerExtension`'s shape (D-27, D-157, D-198) for `UserTrackPreference` — there is
 * no cross-user read path (AC-5.6). No `ApiResource` exposes this entity today (no
 * preference-management endpoint, Q-3); this extension exists so any future read path — an admin
 * screen or a settings surface — inherits the same ownership gate the rest of the app uses, rather
 * than inventing one.
 */
final readonly class UserTrackPreferenceOwnerExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->addOwnerCondition($queryBuilder);
    }

    /** @param array<string, mixed> $identifiers */
    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->addOwnerCondition($queryBuilder);
    }

    private function addOwnerCondition(QueryBuilder $queryBuilder): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? UserTrackPreference::class;

        $queryBuilder
            ->andWhere(\sprintf('%s.owner = :current_user', $rootAlias))
            ->setParameter('current_user', $user);
    }
}
