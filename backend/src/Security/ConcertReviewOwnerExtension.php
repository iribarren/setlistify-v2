<?php

declare(strict_types=1);

namespace App\Security;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\ConcertReview;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * D-229: the second ownership gate for `ConcertReview`, a structural copy of
 * `App\Security\ConcertOwnerExtension` — same two interfaces, same `1 = 0` defensive dead end for
 * an unauthenticated principal, no `ROLE_ADMIN` branch, ever (D-47). Copied verbatim rather than
 * shared through a common base class deliberately: a shared abstract class would make the gate one
 * edit away from being weakened for every user-scoped resource at once.
 *
 * This is the SECOND gate, unreachable in practice: `App\State\ConcertLocator` (itself gated by
 * `ConcertOwnerExtension`) already resolves the parent `Concert` for the current owner *before* any
 * `ConcertReview` query runs — a non-owner's request 404s at that point and this class never even
 * executes for it (D-229, AC-4.2).
 */
final readonly class ConcertReviewOwnerExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
            // No authenticated user: the resource's `security` expression already rejects the
            // request with 401 before a provider ever runs this — a defensive dead end, not a
            // reachable "return everything" path.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? ConcertReview::class;

        $queryBuilder
            ->andWhere(\sprintf('%s.owner = :current_user', $rootAlias))
            ->setParameter('current_user', $user);
    }
}
