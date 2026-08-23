<?php

declare(strict_types=1);

namespace App\Service\Playlist\Stage;

use App\Entity\Playlist;
use App\Entity\PlaylistTrack;
use App\Service\Concert\BandResolver;
use App\Service\Matching\Cache\TrackResolutionStore;
use App\Service\Matching\SongNormalizer;
use App\Service\Playlist\Exception\GenerationBlockedException;
use App\Service\Playlist\Model\BlockedReason;
use App\Service\Playlist\Model\PipelineStage;
use App\Service\Playlist\Model\ReportCode;
use App\Service\Playlist\Model\TrackOutcome;
use App\Service\Streaming\Exception\NotFoundException;
use App\Service\Streaming\Exception\QuotaExhaustedException;
use App\Service\Streaming\Exception\RateLimitedException;
use App\Service\Streaming\Exception\RegionRestrictedException;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\StreamingProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Level-3 idempotency (spec 14 §5, D-137): `addTracks()` over contiguous slices of
 * `insertBatchSize`, advancing `Playlist::$insertedThroughOrdinal` only after each call returns. A
 * resumed run starts at the watermark and never re-sends an earlier batch.
 */
final readonly class InsertionStage
{
    public function __construct(
        private TrackResolutionStore $resolutionStore,
        private SongNormalizer $songNormalizer,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private int $insertBatchSize,
        private int $rateLimitInlineRetries,
    ) {
    }

    public function run(Playlist $playlist, string $providerKey, int $algorithmVersion, StreamingProviderInterface $provider, ProviderTokens $tokens): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        /** @var list<PlaylistTrack> $insertable */
        $insertable = array_values(array_filter(
            $playlist->getTracks()->toArray(),
            static fn (PlaylistTrack $t): bool => $t->getOutcome()->isHit() && null !== $t->getProviderTrackId() && null === $t->getInsertedAt(),
        ));

        if ($playlist->getInsertedThroughOrdinal() > 0) {
            $playlist->addReportEntry(ReportCode::ResumedMidInsertion->value, [], $now);
            $this->entityManager->flush();
        }

        $insertable = array_values(array_filter($insertable, fn (PlaylistTrack $t): bool => $t->getOrdinal() >= $playlist->getInsertedThroughOrdinal()));

        foreach (array_chunk($insertable, max(1, $this->insertBatchSize)) as $batch) {
            $this->insertBatch($playlist, $batch, $providerKey, $algorithmVersion, $provider, $tokens);
        }
    }

    /** @param list<PlaylistTrack> $batch */
    private function insertBatch(Playlist $playlist, array $batch, string $providerKey, int $algorithmVersion, StreamingProviderInterface $provider, ProviderTokens $tokens): void
    {
        $trackIds = [];
        foreach ($batch as $track) {
            $id = $track->getProviderTrackId();
            if (null !== $id) {
                $trackIds[] = $id;
            }
        }

        if ([] === $trackIds) {
            return;
        }

        $attempts = 0;
        while (true) {
            try {
                $provider->addTracks($playlist->getProviderPlaylistId() ?? '', $trackIds, $tokens);
                break;
            } catch (QuotaExhaustedException $e) {
                throw new GenerationBlockedException('Provider quota exhausted during insertion.', BlockedReason::ProviderQuota, null, PipelineStage::Insertion, $e);
            } catch (RateLimitedException $e) {
                ++$attempts;
                if ($attempts > $this->rateLimitInlineRetries) {
                    $resumableAfter = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('+15 minutes');

                    throw new GenerationBlockedException('Provider rate limited during insertion.', BlockedReason::ProviderRateLimit, $resumableAfter, PipelineStage::Insertion, $e);
                }
                usleep((int) (min($e->retryAfterSeconds ?? 1, 30) * 1_000_000));
            } catch (NotFoundException) {
                // F-13: a vanished track. Retried without the offending id(s) would need per-track
                // isolation the port doesn't offer in a batch call; the whole batch is marked
                // not_found and its resolutions invalidated so a future generation re-searches them.
                foreach ($batch as $track) {
                    $this->markVanished($track, $providerKey, $algorithmVersion);
                }
                $this->entityManager->flush();

                return;
            } catch (RegionRestrictedException) {
                // F-11: per-track outcome; the TrackResolution row is NOT invalidated — the track
                // itself is still correct, just unavailable to this user's region.
                foreach ($batch as $track) {
                    $track->resolve(TrackOutcome::RegionRestricted, $track->getProviderTrackId(), $track->getConfidence(), ReportCode::NotAvailableInRegion, []);
                }
                $this->entityManager->flush();

                return;
            }
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach ($batch as $track) {
            $track->markInserted($now);
        }

        $maxOrdinal = $playlist->getInsertedThroughOrdinal();
        foreach ($batch as $track) {
            $maxOrdinal = max($maxOrdinal, $track->getOrdinal());
        }
        $playlist->advanceInsertionWatermark($maxOrdinal + 1, $now);
        $this->entityManager->flush();
    }

    private function markVanished(PlaylistTrack $track, string $providerKey, int $algorithmVersion): void
    {
        $track->resolve(TrackOutcome::NotFound, null, $track->getConfidence(), ReportCode::TrackVanished, []);

        $normalizedArtist = BandResolver::normalize($track->getSourceBand()->getName());
        $normalizedTitle = $this->songNormalizer->normalize($track->getSourceTitle())->comparisonCore;
        $this->resolutionStore->delete($providerKey, $algorithmVersion, $normalizedArtist, $normalizedTitle);
    }
}
