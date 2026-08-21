<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
final class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** @return list<RefreshToken> */
    public function findByFamily(string $family): array
    {
        return $this->findBy(['family' => $family]);
    }

    /** The chain tip: the one unrotated, unrevoked, unexpired token in a family, if any. */
    public function findActiveTipOfFamily(string $family): ?RefreshToken
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.family = :family')
            ->andWhere('t.rotatedAt IS NULL')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('family', $family)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.id', 'DESC')
            ->setMaxResults(1);

        /** @var RefreshToken|null $result */
        $result = $qb->getQuery()->getOneOrNullResult();

        return $result;
    }

    public function revokeFamily(string $family, \DateTimeImmutable $at): void
    {
        $em = $this->getEntityManager();
        foreach ($this->findByFamily($family) as $token) {
            if (null === $token->getRevokedAt()) {
                $token->revoke($at);
            }
        }
        $em->flush();
    }

    public function revokeAllForUser(User $user, \DateTimeImmutable $at): void
    {
        $em = $this->getEntityManager();
        $tokens = $this->findBy(['user' => $user]);
        foreach ($tokens as $token) {
            if (null === $token->getRevokedAt()) {
                $token->revoke($at);
            }
        }
        $em->flush();
    }

    public function save(RefreshToken $token): void
    {
        $em = $this->getEntityManager();
        $em->persist($token);
        $em->flush();
    }

    /** Used by `app:tokens:prune` (R-10). */
    public function deleteExpiredBefore(\DateTimeImmutable $threshold): int
    {
        $affected = $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.expiresAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        return \is_int($affected) ? $affected : 0;
    }
}
