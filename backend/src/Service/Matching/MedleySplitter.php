<?php

declare(strict_types=1);

namespace App\Service\Matching;

/**
 * Splits a medley entry into its constituent titles (spec 12 §5).
 *
 * **setlist.fm has no medley field.** The community convention is a single entry whose title contains
 * the constituent songs separated by ` / ` (occasionally ` > ` for a segue). *That is a convention,
 * not a schema guarantee* — it is recorded here as an assumption and verified against the fixture set,
 * not treated as fact.
 *
 * A false-positive split is possible and deliberately tolerated: the cost is low and self-correcting,
 * because each segment is searched independently and either matches or is reported as not found.
 * Nothing crashes, and the report groups the segments under the parent entry.
 *
 * Splitting is attempted only when EVERY resulting segment is non-empty and at least two characters —
 * which is what stops a stray slash from producing a phantom one-character song.
 */
final readonly class MedleySplitter
{
    private const int MIN_SEGMENT_LENGTH = 2;

    /**
     * @return list<string> the segments in order, or a single-element list holding the original title
     *                      when this is not a medley
     */
    public function split(string $rawTitle): array
    {
        $title = trim($rawTitle);

        $parts = preg_split('~\s+(?:/|>)\s+~u', $title);
        if (false === $parts || \count($parts) < 2) {
            return [$title];
        }

        $segments = [];
        foreach ($parts as $part) {
            $segment = trim($part);
            if (mb_strlen($segment, 'UTF-8') < self::MIN_SEGMENT_LENGTH) {
                return [$title];
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    public function isMedley(string $rawTitle): bool
    {
        return \count($this->split($rawTitle)) > 1;
    }
}
