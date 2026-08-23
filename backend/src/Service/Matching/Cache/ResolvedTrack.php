<?php

declare(strict_types=1);

namespace App\Service\Matching\Cache;

/**
 * The value `TrackResolutionStore` hands back — a Redis-serializable projection of
 * `App\Entity\TrackResolution` (spec 12 §8). `providerTrackId === null` is a cached negative
 * result, exactly as on the entity.
 */
final readonly class ResolvedTrack
{
    /** @param list<array<string, mixed>> $candidatesDigest */
    public function __construct(
        public string $provider,
        public int $algorithmVersion,
        public string $normalizedArtist,
        public string $normalizedTitle,
        public ?string $providerTrackId,
        public float $confidence,
        /** @var 'matched'|'matched_low_confidence'|'not_found' */
        public string $outcome,
        public array $candidatesDigest,
        public \DateTimeImmutable $resolvedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'algorithmVersion' => $this->algorithmVersion,
            'normalizedArtist' => $this->normalizedArtist,
            'normalizedTitle' => $this->normalizedTitle,
            'providerTrackId' => $this->providerTrackId,
            'confidence' => $this->confidence,
            'outcome' => $this->outcome,
            'candidatesDigest' => $this->candidatesDigest,
            'resolvedAt' => $this->resolvedAt->format(\DateTimeInterface::ATOM),
            'expiresAt' => $this->expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        if (
            !\is_string($data['provider'] ?? null)
            || !\is_int($data['algorithmVersion'] ?? null)
            || !\is_string($data['normalizedArtist'] ?? null)
            || !\is_string($data['normalizedTitle'] ?? null)
            || !\is_float($data['confidence'] ?? null) && !\is_int($data['confidence'] ?? null)
            || !\is_string($data['outcome'] ?? null)
            || !\is_array($data['candidatesDigest'] ?? null)
            || !\is_string($data['resolvedAt'] ?? null)
            || !\is_string($data['expiresAt'] ?? null)
        ) {
            return null;
        }

        $providerTrackId = $data['providerTrackId'] ?? null;
        if (null !== $providerTrackId && !\is_string($providerTrackId)) {
            return null;
        }

        /** @var 'matched'|'matched_low_confidence'|'not_found' $outcome */
        $outcome = $data['outcome'];

        /** @var list<array<string, mixed>> $digest */
        $digest = $data['candidatesDigest'];

        return new self(
            provider: $data['provider'],
            algorithmVersion: $data['algorithmVersion'],
            normalizedArtist: $data['normalizedArtist'],
            normalizedTitle: $data['normalizedTitle'],
            providerTrackId: $providerTrackId,
            confidence: (float) $data['confidence'],
            outcome: $outcome,
            candidatesDigest: $digest,
            resolvedAt: new \DateTimeImmutable($data['resolvedAt']),
            expiresAt: new \DateTimeImmutable($data['expiresAt']),
        );
    }
}
