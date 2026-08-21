<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailVerificationToken>
 */
final class EmailVerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationToken::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?EmailVerificationToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function invalidateAllForUser(User $user, \DateTimeImmutable $at): void
    {
        $em = $this->getEntityManager();
        foreach ($this->findBy(['user' => $user]) as $token) {
            if (null === $token->getUsedAt()) {
                $token->markUsed($at);
            }
        }
        $em->flush();
    }

    public function save(EmailVerificationToken $token): void
    {
        $em = $this->getEntityManager();
        $em->persist($token);
        $em->flush();
    }

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
