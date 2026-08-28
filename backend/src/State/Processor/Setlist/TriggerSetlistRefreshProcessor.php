<?php

declare(strict_types=1);

namespace App\State\Processor\Setlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Setlist\BandSetlistRefreshOutput;
use App\Entity\Band;
use App\Entity\User;
use App\Message\RefreshBandSetlistsMessage;
use App\Repository\BandRepository;
use App\Security\Voter\InstantRefreshVoter;
use App\Service\Admin\AuditLogger;
use App\Service\Setlist\SetlistRefreshCoordinator;
use App\State\BandNotOnYourConcertsException;
use App\State\BandOwnershipChecker;
use App\State\Setlist\BandSetlistRefreshOutputMapper;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/bands/{id}/setlist-refresh` (docs/specs/2026-08-27-instant-setlist-refresh.md, US-1,
 * D-256). Validates, throttles, audits, dispatches and returns — **zero** outbound setlist.fm
 * requests happen on this request thread (AC-3.1). The entitlement gate itself
 * (`CAN_REFRESH_SETLIST_NOW`) is declared on the operation's `security:` expression, not here.
 *
 * @implements ProcessorInterface<mixed, BandSetlistRefreshOutput>
 */
final readonly class TriggerSetlistRefreshProcessor implements ProcessorInterface
{
    public function __construct(
        private BandRepository $bandRepository,
        private BandOwnershipChecker $ownershipChecker,
        private SetlistRefreshCoordinator $coordinator,
        private BandSetlistRefreshOutputMapper $mapper,
        private MessageBusInterface $messageBus,
        private AuditLogger $auditLogger,
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

        // AC-1.2: defensive second line — the operation's own security expression is the primary
        // enforcement point, same relationship as MeStateProvider's re-check of IS_AUTHENTICATED_FULLY.
        if (!$this->security->isGranted(InstantRefreshVoter::ATTRIBUTE, $user)) {
            throw new AccessDeniedHttpException();
        }

        $band = $this->bandRepository->find($uriVariables['bandId'] ?? null);
        if (!$band instanceof Band) {
            throw new NotFoundHttpException();
        }

        // D-266/AC-1.3/AC-1.4: reuses ConcertOwnerExtension via BandOwnershipChecker — no new query
        // extension, ConcertOwnerExtension itself untouched.
        if (!$this->ownershipChecker->ownsAConcertFeaturing($band)) {
            throw new BandNotOnYourConcertsException();
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $decision = $this->coordinator->trigger($band, $user, $now);

        if ('refused' === $decision->kind) {
            \assert(null !== $decision->refusedReason && null !== $decision->retryAfterAt);
            $this->overrideResponse($context, 429, $decision->retryAfterAt, $now);

            return $this->mapper->refused($band, $decision->refusedReason, $decision->retryAfterAt);
        }

        if ('alreadyInFlight' === $decision->kind) {
            \assert(null !== $decision->record);
            $this->overrideResponse($context, 200, null, $now);

            return $this->mapper->fromRecord($band, $decision->record);
        }

        \assert(null !== $decision->record);

        // AC-8.1/AC-8.2: written in the request thread — AuditLogger reads IP/User-Agent off
        // RequestStack, which a Messenger worker doesn't have.
        $this->auditLogger->log(
            actor: $user,
            action: 'trigger_setlist_refresh',
            subjectType: 'Band',
            subjectId: $band->getId() ?? 0,
            field: 'setlistfmResolutionState',
            oldValue: $band->getSetlistfmResolutionState(),
            newValue: 'requested',
        );

        $this->messageBus->dispatch(new RefreshBandSetlistsMessage($band->getId() ?? 0, false));

        return $this->mapper->fromRecord($band, $decision->record);
    }

    /** @param array<string, mixed> $context */
    private function overrideResponse(array $context, int $status, ?\DateTimeImmutable $retryAfterAt, \DateTimeImmutable $now): void
    {
        if (!isset($context['request']) || !$context['request'] instanceof Request) {
            return;
        }

        $context['request']->attributes->set('_setlist_refresh_status_override', $status);
        if (null !== $retryAfterAt) {
            $context['request']->attributes->set('_setlist_refresh_retry_after', max(1, $retryAfterAt->getTimestamp() - $now->getTimestamp()));
        }
    }
}
