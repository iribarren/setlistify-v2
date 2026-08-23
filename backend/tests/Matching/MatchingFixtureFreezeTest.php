<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Tests\Support\Matching\MatchingFixtureManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Enforces D-122's freeze rule: "a pull request may not both add fixtures and change the algorithm
 * in the same pull request, because that makes the before/after incomparable."
 *
 * This test does not know whether a diff touched the algorithm — it cannot, from inside a fixture's
 * own checksum. What it CAN do, and does, is make an unannounced fixture edit impossible to miss: it
 * hashes `tests/Fixtures/matching/manifest.yaml` and everything under `tests/Fixtures/matching/
 * catalog/`, and fails loudly the moment that hash drifts from the committed
 * `tests/Fixtures/matching/manifest.sha256`. A reviewer then asks the one question the freeze rule
 * exists to force: *is this PR just adding fixtures (fine, land it, record today's numbers), or is it
 * also changing the algorithm (not fine — split it)?*
 *
 * To deliberately update the fixture set (new fixtures, corrected labels, the eventual catalog
 * capture pass):
 *
 *   1. Edit `tests/Fixtures/matching/manifest.yaml` (and/or `tests/Fixtures/matching/catalog/`).
 *   2. Regenerate the checksum:
 *      `docker compose exec backend php -r '
 *          require "vendor/autoload.php";
 *          file_put_contents(
 *              App\Tests\Support\Matching\MatchingFixtureManifest::checksumPath(),
 *              App\Tests\Support\Matching\MatchingFixtureManifest::computeChecksum()."\n"
 *          );
 *      '`
 *   3. Commit both the fixture change and the regenerated `manifest.sha256` in the SAME commit, with
 *      NO accompanying change under `src/Service/Matching/` or `config/matching/` — that is what
 *      keeps the fixture set frozen relative to the algorithm it evaluates.
 */
#[Group('matching-quality')]
final class MatchingFixtureFreezeTest extends TestCase
{
    public function testManifestChecksumMatchesTheCommittedFreeze(): void
    {
        $committed = MatchingFixtureManifest::readCommittedChecksum();
        self::assertNotNull(
            $committed,
            \sprintf(
                '%s is missing. Generate it (see this test\'s docblock) and commit it alongside the manifest — a fixture set with no committed checksum cannot be frozen.',
                MatchingFixtureManifest::checksumPath(),
            ),
        );

        $actual = MatchingFixtureManifest::computeChecksum();

        self::assertSame(
            $committed,
            $actual,
            'D-122: tests/Fixtures/matching/manifest.yaml and/or tests/Fixtures/matching/catalog/ '
            .'changed without a deliberate checksum update. If this change is ONLY adding/correcting '
            .'fixtures (no change under src/Service/Matching/ or config/matching/ in the same PR), '
            .'regenerate manifest.sha256 (see this test\'s docblock) and commit it. If this change '
            .'ALSO touches the algorithm, split it: fixtures land first with today\'s numbers '
            .'recorded, the algorithm change lands second against the now-frozen set.',
        );
    }
}
