<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Playlist;

use App\Entity\Band;
use App\Service\Playlist\Model\NoSetlistCause;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** T-1: every `Band::RESOLUTION_*` constant maps to a cause; an unknown state throws, never defaults. */
#[CoversClass(NoSetlistCause::class)]
final class NoSetlistCauseTest extends TestCase
{
    public function testMapsAllFourResolutionStates(): void
    {
        self::assertSame(NoSetlistCause::BandUnknown, NoSetlistCause::forResolutionState(Band::RESOLUTION_NO_PRESENCE));
        self::assertSame(NoSetlistCause::BandAmbiguous, NoSetlistCause::forResolutionState(Band::RESOLUTION_AMBIGUOUS));
        self::assertSame(NoSetlistCause::NoSetlistForShow, NoSetlistCause::forResolutionState(Band::RESOLUTION_RESOLVED));
        self::assertSame(NoSetlistCause::IdentityUnavailable, NoSetlistCause::forResolutionState(Band::RESOLUTION_UNRESOLVED));
    }

    public function testThrowsOnAnUnknownStateRatherThanSilentlyDefaulting(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NoSetlistCause::forResolutionState('some_future_state');
    }
}
