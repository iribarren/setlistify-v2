<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Playlist;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\Exception\PlaylistCreationIndeterminateException;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Streaming\Exception\QuotaExhaustedException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Model\PlaylistDraft;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\StreamingProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Level-2 idempotency (spec 14 §5, D-136): commit `creationAttemptedAt` BEFORE any network call,
 * call `createPlaylist()`, commit `providerPlaylistId` + `externalUrl` after. F-14's indeterminate
 * window — marker set, id still null — stops here rather than risking a second create (P-3).
 */
final readonly class CreationStage
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function run(Playlist $playlist, string $description, StreamingProviderInterface $provider, ProviderTokens $tokens): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if (null !== $playlist->getProviderPlaylistId()) {
            // Ordinary retry path: already created, reuse the id (spec 14 §5's idempotency table).
            return;
        }

        if ($playlist->isCreationIndeterminate()) {
            throw new PlaylistCreationIndeterminateException(\sprintf('Playlist #%s: creation was attempted but never confirmed.', $playlist->getId() ?? '?'));
        }

        $playlist->setDescription($description, $now);
        $playlist->markCreationAttempted($now);
        $this->entityManager->flush();

        try {
            $created = $provider->createPlaylist(new PlaylistDraft($playlist->getName(), $description), $tokens);
        } catch (QuotaExhaustedException $e) {
            throw new GenerationBlockedException('Provider quota exhausted during playlist creation.', BlockedReason::ProviderQuota, null, PipelineStage::Creation, $e);
        } catch (RateLimitedException $e) {
            $resumableAfter = $now->modify('+15 minutes');

            throw new GenerationBlockedException('Provider rate limited during playlist creation.', BlockedReason::ProviderRateLimit, $resumableAfter, PipelineStage::Creation, $e);
        }
        // F-12 (ProviderUnavailableException, or any other \Throwable) is deliberately left
        // uncaught here: it propagates to Messenger's own retry policy. The creation marker is
        // already committed above, so a redelivery correctly sees `isCreationIndeterminate()` and
        // stops rather than creating twice.

        $playlist->confirmCreated($created->providerPlaylistId, $created->externalUrl, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();
    }
}
