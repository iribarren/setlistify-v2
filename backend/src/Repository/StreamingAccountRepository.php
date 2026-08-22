<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StreamingAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StreamingAccount>
 */
final class StreamingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StreamingAccount::class);
    }

    public function createStreamingAccountQueryBuilder(string $alias = 'sa'): QueryBuilder
    {
        return $this->createQueryBuilder($alias);
    }

    /** Not owner-filtered — only for the link flow, which already knows the owner from the pending link. */
    public function findOneByUserAndProvider(int $userId, string $provider): ?StreamingAccount
    {
        return $this->createQueryBuilder('sa')
            ->andWhere('sa.user = :user_id')
            ->andWhere('sa.provider = :provider')
            ->setParameter('user_id', $userId)
            ->setParameter('provider', $provider)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(StreamingAccount $account): void
    {
        $em = $this->getEntityManager();
        $em->persist($account);
        $em->flush();
    }

    public function remove(StreamingAccount $account): void
    {
        $em = $this->getEntityManager();
        $em->remove($account);
        $em->flush();
    }
}
