<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * The shape of a concert as read (AC-3.2). `status` is derived, never stored (D-24); `lineup` is
 * ordered by billing order ascending (AC-1.4). Omitted optional fields are `null`, never absent
 * (AC-2.7), so the generated client's types are stable.
 */
final readonly class ConcertOutput
{
    /** @param list<LineupEntryOutput> $lineup */
    public function __construct(
        public int $id,
        public string $date,
        public string $timezone,
        /** @var 'upcoming'|'past' */
        public string $status,
        public array $lineup,
        public VenueData $venue,
        public ?MoneyData $ticketPrice,
        public ?string $doorsTime,
        public ?string $startTime,
        public ?string $note,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
