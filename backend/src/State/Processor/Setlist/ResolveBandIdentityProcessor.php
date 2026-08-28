<?php

declare(strict_types=1);

namespace App\State\Processor\Setlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Setlist\BandSetlistRefreshOutput;
use App\ApiResource\Setlist\ResolveBandIdentityInput;
use App\Entity\Band;
use App\Entity\User;
use App\Message\RefreshBandSetlistsMessage;
use App\Repository\BandRepository;
use App\Security\Voter\InstantRefreshVoter;
use App\Service\Admin\AuditLogger;
use App\Service\Setlist\ArtistSearchCandidate;
use App\Service\Setlist\BandAlreadyResolvedException;
use App\Service\Setlist\BandIdentityResolver;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\State\BandNotOnYourConcertsException;
use App\State\BandOwnershipChecker;
use App\State\Setlist\BandSetlistRefreshOutputMapper;
use App\State\SetlistRefreshValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/bands/{id}/setlist-refresh/resolution` (docs/specs/2026-08-27-instant-setlist-refresh.md,
 * D-278, US-6). The user-side disambiguation pick: vacancy-only, candidate-set-only, once-only
 * (D-270, D-271, D-276).
 *
 * @implements ProcessorInterface<ResolveBandIdentityInput, BandSetlistRefreshOutput>
 */
final readonly class ResolveBandIdentityProcessor implements ProcessorInterface
{
    public function __construct(
        private BandRepository $bandRepository,
        private BandOwnershipChecker $ownershipChecker,
        private SetlistRefreshCoordinator $coordinator,
        private BandIdentityResolver $resolver,
        private BandSetlistRefreshOutputMapper $mapper,
        private MessageBusInterface $messageBus,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BandSetlistRefreshOutput
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        if (!$this->security->isGranted(InstantRefreshVoter::ATTRIBUTE, $user)) {
            throw new AccessDeniedHttpException();
        }

        $selectedMbid = (string) $data->selectedMbid;

        $band = $this->bandRepository->find($uriVariables['bandId'] ?? null);
        if (!$band instanceof Band) {
            throw new NotFoundHttpException();
        }

        // AC-6.9: the same two gates as the trigger, reusing the same code paths — not duplicated.
        if (!$this->ownershipChecker->ownsAConcertFeaturing($band)) {
            throw new BandNotOnYourConcertsException();
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return $this->coordinator->withBandLock((int) ($band->getId() ?? 0), function () use ($band, $user, $selectedMbid, $now): BandSetlistRefreshOutput {
            // AC-6.14: re-read fresh state under the same lock the trigger uses, so a concurrent
            // pick on this band can never win a race against this one.
            $this->entityManager->refresh($band);

            $record = $this->coordinator->getRecord((int) ($band->getId() ?? 0));
            $chosen = $this->findCandidate(null !== $record ? $record->candidates : [], $selectedMbid);
            if (null === $chosen) {
                // AC-6.6: an MBID not among the exact candidate set this band's most recent refresh
                // showed — including an expired record — is refused, never a free-text write (D-271).
                throw new SetlistRefreshValidationException('mbid_not_a_candidate');
            }

            $stateBeforePick = $band->getSetlistfmResolutionState();

            try {
                $this->resolver->resolveAmbiguousChoice($band, $chosen, $now);
            } catch (BandAlreadyResolvedException) {
                throw new SetlistRefreshValidationException('band_already_resolved');
            }

            // AC-8.7: audited under its own action name, distinct from the operator's correction —
            // in the request thread (AuditLogger needs RequestStack).
            $this->auditLogger->log(
                actor: $user,
                action: 'choose_band_mbid',
                subjectType: 'Band',
                subjectId: $band->getId() ?? 0,
                field: 'setlistfmMbid',
                oldValue: $stateBeforePick,
                newValue: $chosen->mbid,
            );

            // AC-6.12/D-277: completes as a one-request refresh — daily cap and budget reserve still
            // apply, but exempt from the cooldown (the identity just changed).
            $completion = $this->coordinator->acceptPickCompletion($band, $user, $now);

            if ('accepted' === $completion->kind) {
                $this->messageBus->dispatch(new RefreshBandSetlistsMessage($band->getId() ?? 0, true));

                return $this->mapper->fromRecord($band, $completion->record);
            }

            // The identity write already succeeded and is durable/audited regardless — only the
            // immediate setlist fetch is refused. The caller can retry the plain trigger later,
            // subject to the normal throttles (the cooldown does not apply retroactively here).
            \assert(null !== $completion->refusedReason && null !== $completion->retryAfterAt);

            return $this->mapper->refused($band, $completion->refusedReason, $completion->retryAfterAt);
        });
    }

    /** @param list<ArtistSearchCandidate> $candidates */
    private function findCandidate(array $candidates, string $selectedMbid): ?ArtistSearchCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->mbid === $selectedMbid) {
                return $candidate;
            }
        }

        return null;
    }
}
