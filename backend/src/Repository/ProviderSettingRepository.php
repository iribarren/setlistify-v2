<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Referenced by exactly two classes (AC-10.1): `App\Service\Provider\ProviderRegistry` (reads) and
 * `App\Service\Provider\ProviderSettingWriter` (writes) — see {@see ProviderSetting}'s docblock for
 * the one disclosed exception.
 *
 * @extends ServiceEntityRepository<ProviderSetting>
 */
final class ProviderSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderSetting::class);
    }

    public function findOneByProvider(string $provider): ?ProviderSetting
    {
        $result = $this->createQueryBuilder('ps')
            ->andWhere('ps.provider = :provider')
            ->setParameter('provider', $provider)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ProviderSetting ? $result : null;
    }

    public function findCurrentDefault(): ?ProviderSetting
    {
        $result = $this->createQueryBuilder('ps')
            ->andWhere('ps.isDefault = true')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ProviderSetting ? $result : null;
    }

    /** @return list<ProviderSetting> every row, ordered for stable rendering (D-93's snapshot is built from this) */
    public function findAllOrderedByProvider(): array
    {
        /** @var list<ProviderSetting> $rows */
        $rows = $this->createQueryBuilder('ps')
            ->orderBy('ps.provider', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
