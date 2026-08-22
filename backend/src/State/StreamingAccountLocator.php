<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use App\Entity\StreamingAccount;
use App\Repository\StreamingAccountRepository;
use App\Security\StreamingAccountOwnerExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mirrors `App\State\ConcertLocator` exactly (D-77): loads a `StreamingAccount` by id, pre-filtered
 * to the current owner by `StreamingAccountOwnerExtension`, then re-checks with
 * `App\Security\Voter\StreamingAccountVoter` as the second gate. Both "no such id" and "someone
 * else's id" reach the exact same `NotFoundHttpException`.
 */
final readonly class StreamingAccountLocator
{
    public function __construct(
        private StreamingAccountRepository $repository,
        private StreamingAccountOwnerExtension $ownerExtension,
        private Security $security,
    ) {
    }

    /** @param non-empty-string $voterAttribute one of `StreamingAccountVoter::VIEW`/`DELETE` */
    public function locate(mixed $id, string $voterAttribute): StreamingAccount
    {
        if (!is_numeric($id)) {
            throw new NotFoundHttpException();
        }

        $intId = (int) $id;

        $queryBuilder = $this->repository->createStreamingAccountQueryBuilder('sa');
        $this->ownerExtension->applyToItem($queryBuilder, new QueryNameGenerator(), StreamingAccount::class, ['id' => $intId]);
        $queryBuilder->andWhere('sa.id = :streaming_account_id')->setParameter('streaming_account_id', $intId);

        $account = $queryBuilder->getQuery()->getOneOrNullResult();

        if (!$account instanceof StreamingAccount) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted($voterAttribute, $account)) {
            // Unreachable today — the query above already filtered to the current owner. Kept as
            // the second gate (D-77) for a future path that reaches a StreamingAccount without it.
            throw new AccessDeniedHttpException();
        }

        return $account;
    }
}
