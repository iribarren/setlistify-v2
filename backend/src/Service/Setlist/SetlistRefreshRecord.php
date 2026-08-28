<?php

declare(strict_types=1);

namespace App\Service\Setlist;

/**
 * The Redis-held state of one band's on-demand refresh (docs/specs/2026-08-27-instant-setlist-refresh.md,
 * D-264, AC-3.4, AC-6.4). Its durable trace is the audit entry, not this record (D-264) — a Redis
 * flush self-heals within one cooldown window.
 */
final readonly class SetlistRefreshRecord
{
    /**
     * @param 'queued'|'running'|'succeeded'|'failed'                       $state
     * @param list<ArtistSearchCandidate>                                   $candidates    AC-6.4: the exact set shown, so a
     *                                                                                     later pick validates against it
     * @param 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null $failureReason
     */
    public function __construct(
        public int $bandId,
        public string $state,
        public \DateTimeImmutable $requestedAt,
        public ?\DateTimeImmutable $finishedAt,
        public string $bandStateBefore,
        public ?string $bandStateAfter,
        public array $candidates,
        public ?\DateTimeImmutable $cooldownUntil,
        public ?string $failureReason,
        /** @var 'live'|'cache'|null */
        public ?string $freshnessSource = null,
        public ?\DateTimeImmutable $freshnessFetchedAt = null,
        public bool $freshnessStale = false,
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null */
        public ?string $freshnessReason = null,
        public ?\DateTimeImmutable $freshnessBudgetResetAt = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bandId' => $this->bandId,
            'state' => $this->state,
            'requestedAt' => $this->requestedAt->format(\DateTimeInterface::ATOM),
            'finishedAt' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
            'bandStateBefore' => $this->bandStateBefore,
            'bandStateAfter' => $this->bandStateAfter,
            'candidates' => array_map(static fn (ArtistSearchCandidate $c): array => [
                'mbid' => $c->mbid,
                'name' => $c->name,
                'sortName' => $c->sortName,
                'disambiguation' => $c->disambiguation,
                'url' => $c->url,
            ], $this->candidates),
            'cooldownUntil' => $this->cooldownUntil?->format(\DateTimeInterface::ATOM),
            'failureReason' => $this->failureReason,
            'freshnessSource' => $this->freshnessSource,
            'freshnessFetchedAt' => $this->freshnessFetchedAt?->format(\DateTimeInterface::ATOM),
            'freshnessStale' => $this->freshnessStale,
            'freshnessReason' => $this->freshnessReason,
            'freshnessBudgetResetAt' => $this->freshnessBudgetResetAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawCandidates = \is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        $candidates = [];
        foreach ($rawCandidates as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $candidates[] = new ArtistSearchCandidate(
                mbid: self::asString($raw['mbid'] ?? null),
                name: self::asString($raw['name'] ?? null),
                sortName: self::asStringOrNull($raw['sortName'] ?? null),
                disambiguation: self::asStringOrNull($raw['disambiguation'] ?? null),
                url: self::asStringOrNull($raw['url'] ?? null),
            );
        }

        /** @var 'queued'|'running'|'succeeded'|'failed' $state */
        $state = self::asString($data['state'] ?? null);
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null $failureReason */
        $failureReason = self::asStringOrNull($data['failureReason'] ?? null);
        /** @var 'live'|'cache'|null $freshnessSource */
        $freshnessSource = self::asStringOrNull($data['freshnessSource'] ?? null);
        /** @var 'budget_exhausted'|'rate_limited'|'upstream_unavailable'|null $freshnessReason */
        $freshnessReason = self::asStringOrNull($data['freshnessReason'] ?? null);

        return new self(
            bandId: \is_numeric($data['bandId'] ?? null) ? (int) $data['bandId'] : 0,
            state: $state,
            requestedAt: new \DateTimeImmutable(self::asString($data['requestedAt'] ?? null)),
            finishedAt: null !== self::asStringOrNull($data['finishedAt'] ?? null) ? new \DateTimeImmutable(self::asString($data['finishedAt'])) : null,
            bandStateBefore: self::asString($data['bandStateBefore'] ?? null),
            bandStateAfter: self::asStringOrNull($data['bandStateAfter'] ?? null),
            candidates: $candidates,
            cooldownUntil: null !== self::asStringOrNull($data['cooldownUntil'] ?? null) ? new \DateTimeImmutable(self::asString($data['cooldownUntil'])) : null,
            failureReason: $failureReason,
            freshnessSource: $freshnessSource,
            freshnessFetchedAt: null !== self::asStringOrNull($data['freshnessFetchedAt'] ?? null) ? new \DateTimeImmutable(self::asString($data['freshnessFetchedAt'])) : null,
            freshnessStale: (bool) ($data['freshnessStale'] ?? false),
            freshnessReason: $freshnessReason,
            freshnessBudgetResetAt: null !== self::asStringOrNull($data['freshnessBudgetResetAt'] ?? null) ? new \DateTimeImmutable(self::asString($data['freshnessBudgetResetAt'])) : null,
        );
    }

    private static function asString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function asStringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    public function isActive(): bool
    {
        return 'queued' === $this->state || 'running' === $this->state;
    }
}
