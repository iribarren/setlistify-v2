<?php

declare(strict_types=1);

namespace App\Security;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\StreamingAccount;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * D-77: copies `App\Security\ConcertOwnerExtension`'s shape exactly, deliberately duplicated rather
 * than generalised (`CLAUDE.md` — every later user-scoped resource copies D-27's pattern; the
 * original class is never modified). Adds `WHERE user = :current_user` to every `StreamingAccount`
 * query — collection and item alike — so a cross-owner item lookup finds nothing and produces the
 * framework's ordinary "not found" 404, byte-identical to a genuinely missing id. A 403 would
 * confirm the id exists, and for a resource whose existence reveals that a specific person uses a
 * given streaming provider, that leak is worth closing even though the id is not guessable.
 *
 * `App\State\StreamingAccountLocator` calls this directly, the same way `App\State\ConcertLocator`
 * calls `ConcertOwnerExtension` — this feature's resource is also custom-DTO-bound (not
 * entity-bound), so API Platform's automatic extension pipeline does not apply.
 */
final readonly class StreamingAccountOwnerExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
            // request with 401 before a provider ever runs this — this is a defensive dead end.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0] ?? StreamingAccount::class;

        $queryBuilder
            ->andWhere(\sprintf('%s.user = :current_user', $rootAlias))
            ->setParameter('current_user', $user);
    }
}
