<?php

declare(strict_types=1);

namespace App\Security;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Concert;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * D-27: the primary ownership gate. Adds `WHERE owner = :current_user` to every `Concert` query —
 * collection and item alike — so a cross-user item lookup finds nothing and produces the framework's
 * ordinary "not found" 404, byte-identical to a genuinely missing id (AC-7.2). `App\Security\Voter\
 * ConcertVoter` is the second gate, for any future code path that reaches a `Concert` without going
 * through this extension.
 *
 * `App\State\Provider\ConcertCollectionProvider` and `App\State\Provider\ConcertItemProvider` call
 * this directly (this feature builds its own state providers per D-29, so API Platform's automatic
 * extension pipeline for entity-bound resources does not apply) — the class still implements API
 * Platform's real extension interfaces so the pattern is drop-in reusable by a later resource that
 * *does* use the automatic pipeline (playlists, notes).
 */
final readonly class ConcertOwnerExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
            // request with 401 before a provider ever runs this — this is a defensive dead end, not
            // a reachable "return everything" path.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? Concert::class;

        $queryBuilder
            ->andWhere(\sprintf('%s.owner = :current_user', $rootAlias))
            ->setParameter('current_user', $user);
    }
}
