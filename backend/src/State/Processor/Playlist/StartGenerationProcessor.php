<?php

declare(strict_types=1);

namespace App\State\Processor\Playlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Playlist\PlaylistGenerationJobOutput;
use App\ApiResource\Playlist\StartGenerationInput;
use App\Entity\PlaylistGenerationJob;
use App\Entity\StreamingAccount;
use App\Entity\User;
use App\Message\BuildPlaylistMessage;
use App\Repository\PlaylistGenerationJobRepository;
use App\Repository\StreamingAccountRepository;
use App\Security\Voter\ConcertVoter;
use App\Service\Matching\MatchProfileRegistry;
use App\Service\Playlist\Model\JobMode;
use App\Service\Provider\ProviderRegistry;
use App\Service\Provider\ProviderDisabledException;
use App\Service\Streaming\StreamingProviderLocator;
use App\State\ConcertLocator;
use App\State\PlaylistGenerationJobOutputMapper;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /api/playlist-generation-jobs` (spec 14 §6, D-129). Level-1 idempotency: a
 * `UniqueConstraintViolationException` on `uniq_live_generation` means a live job already exists —
 * the existing job is returned with 200, never a second job, never a 409 (AC-1.5).
 *
 * @implements ProcessorInterface<StartGenerationInput, PlaylistGenerationJobOutput>
 */
final readonly class StartGenerationProcessor implements ProcessorInterface
{
    public function __construct(
        private ConcertLocator $concertLocator,
        private PlaylistGenerationJobRepository $jobRepository,
        private StreamingAccountRepository $streamingAccountRepository,
        private ProviderRegistry $providerAvailability,
        private StreamingProviderLocator $streamingProviderLocator,
        private MatchProfileRegistry $matchProfileRegistry,
        private PlaylistGenerationJobOutputMapper $mapper,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlaylistGenerationJobOutput
    {
        $owner = $this->security->getUser();
        if (!$owner instanceof User) {
            throw new AccessDeniedHttpException();
        }

        \assert(null !== $data->concertId);
        $concert = $this->concertLocator->locate($data->concertId, ConcertVoter::VIEW);

        $providerKey = $this->resolveProviderKey($owner, $data->provider);
        $account = $this->streamingAccountRepository->findOneByUserAndProvider($owner->getId() ?? 0, $providerKey);
        if (!$account instanceof StreamingAccount || StreamingAccount::STATUS_CONNECTED !== $account->getStatus()) {
            throw new UnprocessableEntityHttpException(\sprintf('No connected streaming account for provider "%s".', $providerKey));
        }

        $existing = $this->jobRepository->findLiveJob($concert->getId() ?? 0, $providerKey);
        if (null !== $existing) {
            $this->markExistingResponse($context);

            return $this->mapper->map($existing);
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $algorithmVersion = $this->matchProfileRegistry->algorithmVersion();
        $idempotencyKey = self::idempotencyKey($concert->getId() ?? 0, $providerKey, JobMode::Fast, $algorithmVersion);

        $job = new PlaylistGenerationJob($owner, $concert, $providerKey, $account, JobMode::Fast, $idempotencyKey, $algorithmVersion, $now);

        try {
            $this->jobRepository->save($job);
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent POSTs collided on the database, not on this check (D-129) — the loser
            // re-reads and returns the winner's row with 200, never a second job.
            $this->entityManager->clear();
            $winner = $this->jobRepository->findLiveJob($concert->getId() ?? 0, $providerKey);
            if (null !== $winner) {
                $this->markExistingResponse($context);

                return $this->mapper->map($winner);
            }

            throw $e;
        }

        $this->messageBus->dispatch(new BuildPlaylistMessage($job->getId() ?? 0, $job->getAttempt()));

        return $this->mapper->map($job);
    }

    private function resolveProviderKey(User $owner, ?string $requested): string
    {
        if (null !== $requested) {
            if (!$this->streamingProviderLocator->has($requested)) {
                throw new NotFoundHttpException();
            }

            if (!$this->providerAvailability->isAvailable($requested)) {
                throw new ProviderDisabledException($requested);
            }

            return $requested;
        }

        foreach ($this->providerAvailability->all() as $config) {
            if ($config->isDefault && $config->enabled) {
                return $config->key;
            }
        }

        // No default provider configured — the same 404 shape as an explicitly unknown key
        // (spec 14 §6, mirroring App\State\Processor\StreamingLinkStartProcessor's precedent).
        throw new NotFoundHttpException();
    }

    /** @param array<string, mixed> $context */
    private function markExistingResponse(array $context): void
    {
        if (isset($context['request']) && $context['request'] instanceof Request) {
            $context['request']->attributes->set('_playlist_status_override', 200);
        }
    }

    private static function idempotencyKey(int $concertId, string $providerKey, JobMode $mode, int $algorithmVersion): string
    {
        // `sourceFingerprint` is empty for Fast mode (spec 14 §5) — there is no user-chosen setlist
        // or version to fingerprint; the key's job is equality between a job and its own retry
        // (T-16), not uniqueness (that is the partial index's job).
        return hash('sha256', \sprintf('%d|%s|%s|%d|', $concertId, $providerKey, $mode->value, $algorithmVersion));
    }
}
