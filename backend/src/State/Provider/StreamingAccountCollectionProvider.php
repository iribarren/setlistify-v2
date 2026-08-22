<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Repository\StreamingAccountRepository;
use App\Security\StreamingAccountOwnerExtension;
use App\State\StreamingAccountOutputMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `GET /api/streaming/accounts` (US-2, AC-2.1, AC-2.4). Ownership is filtered before anything else
 * via `StreamingAccountOwnerExtension` — mirrors `App\State\Provider\ConcertCollectionProvider`'s
 * shape. No pagination: a user has at most a handful of linked providers, so the whole set is
 * returned as one collection.
 *
 * @implements ProviderInterface<\App\ApiResource\StreamingAccountOutput>
 */
final readonly class StreamingAccountCollectionProvider implements ProviderInterface
{
    public function __construct(
        private StreamingAccountRepository $repository,
        private StreamingAccountOwnerExtension $ownerExtension,
        private StreamingAccountOutputMapper $mapper,
        private Security $security,
    ) {
    }

    /** @return list<\App\ApiResource\StreamingAccountOutput> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        if (!$this->security->getUser() instanceof User) {
            return [];
        }

        $queryBuilder = $this->repository->createStreamingAccountQueryBuilder('sa');
        $this->ownerExtension->applyToCollection($queryBuilder, new QueryNameGenerator(), StreamingAccount::class, $operation);
        $queryBuilder->orderBy('sa.linkedAt', 'ASC');

        /** @var list<StreamingAccount> $accounts */
        $accounts = $queryBuilder->getQuery()->getResult();

        return array_map($this->mapper->map(...), $accounts);
    }
}
