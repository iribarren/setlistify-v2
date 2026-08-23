<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Service\Matching\MatchProfileRegistry;
use App\Service\Matching\MedleySplitter;
use App\Service\Matching\Model\MatchOutcome;
use App\Service\Matching\Model\MatchResult;
use App\Service\Matching\SongNormalizer;
use App\Service\Matching\TrackMatcher;
use App\Service\Streaming\Model\AlbumType;
use App\Service\Streaming\Model\ArtistAuthority;
use App\Service\Streaming\Model\ProviderTokens;
use App\Service\Streaming\Model\TrackCandidate;
use App\Tests\Support\Matching\MatchingFixtureManifest;
use App\Tests\Support\Matching\ScriptedMatchingProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The fixture-based regression gate spec 12 §9 calls "the only thing standing between a matching
 * tweak and a silent regression across every future generation" (D-122/D-123). Runs entirely on
 * committed fixtures (`tests/Fixtures/matching/manifest.yaml`), zero outbound calls, per D-2/D-70/
 * D-85 — mirrors the posture `App\Tests\Setlist\SetlistFmLiveSmokeTest` documents for the one
 * exception to that rule.
 *
 * ## What is armed right now
 *
 * The `structural` fixtures — non-song classification (incl. two real songs that collide with the
 * artifact lexicon, so a classifier regression trips a real assertion, not a vibe), medley/segue
 * splitting, and diacritic/leading-article normalization — need no provider catalog to know the
 * right answer, so they run for real and their gates are enforced: `testNonSongClassification
 * PrecisionMeetsTheHardRequirement()` fails the build on any non-song misclassification (D-122's
 * "= 1.00, hard" requirement, spec §9).
 *
 * ## What is NOT armed, and exactly what a human must supply to arm it
 *
 * Spec §9's PRIMARY metric — auto-accept precision — and its two siblings (coverage, silent-error
 * rate) need real provider search responses and a hand-labelled expected track id per song. Neither
 * exists yet: there are no live Spotify credentials in this environment and no human labeller
 * available. Fabricating either would make `testAutoAcceptPrecisionCoverageAndSilentErrorRateGate()`
 * either lie (a fake green) or block CI on made-up numbers — both worse than an honest skip. That
 * test therefore markTestSkipped()s with the checklist below until `tests/Fixtures/matching/
 * catalog/` is populated; the code path that WOULD run the cascade and compute the metrics is fully
 * wired up beneath the skip guard, not stubbed out, so dropping fixtures in arms it immediately.
 *
 * **Capture checklist** (spec 12 §9's fixture table):
 *
 *  1. Spotify developer credentials with the `user-read-private` scope is NOT needed for search —
 *     only a Client Credentials or Authorization Code token good for `GET /v1/search` is required.
 *     Register an app at https://developer.spotify.com/dashboard, capture a token, and use it ONLY
 *     to record `searchTrack()` responses to JSON — never commit the token itself.
 *  2. Pull the eight real, hand-labelled setlists from setlist.fm (2 requests/second, 1,440/day —
 *     CLAUDE.md's setlist.fm budget rule applies to the CAPTURE pass too, not just production):
 *       - Radiohead — Madison Square Garden, New York, 2018
 *       - Bruce Springsteen & The E Street Band — Estadi Olímpic, Barcelona, 2023
 *       - Pearl Jam — 2022 tour (any single show)
 *       - Metallica — M72 tour, Johan Cruijff ArenA, Amsterdam, 2023
 *       - Sigur Rós — 2022 tour (any single show)
 *       - Vetusta Morla — WiZink Center, Madrid, 2023
 *       - Phish — any 2023 show
 *       - Alcalá Norte — the Madrid support slot from the Vetusta Morla bill above, 2023
 *  3. For every song in every setlist, capture ONE Spotify `searchTrack()` response verbatim (D-120:
 *     one search per song, no speculative second search) and save it as
 *     `tests/Fixtures/matching/catalog/spotify/<setlist-id>/<song-slug>.json` — an array of
 *     `TrackCandidate`-shaped objects (see `catalogFixtureToTrackCandidate()` below for the exact
 *     field mapping).
 *  4. One human, one pass, over every song (~180-220 entries, spec §9): record the expected outcome
 *     (`matched` / `matched_low_confidence` / `not_found` / `skipped`), the expected Spotify track id
 *     when applicable, and a free-text reason — including for ambiguous entries (a `Jam` that might
 *     be filler). Add each as an entry under `catalog:` in `tests/Fixtures/matching/manifest.yaml`
 *     (see the commented shape at the bottom of that file).
 *  5. Repeat the capture pass for YouTube once prompt 18 lands the second adapter — the harness
 *     already computes metrics per provider key found in the manifest, so a second provider is a
 *     fixture-data change only.
 *  6. Regenerate `tests/Fixtures/matching/manifest.sha256` (see `MatchingFixtureFreezeTest`'s
 *     docblock) and commit fixtures + checksum in one PR, with NO algorithm change alongside it
 *     (D-122's freeze rule) — record the resulting numbers in spec 12 §9/§3 in the SAME PR.
 */
#[Group('matching-quality')]
final class MatchingQualityHarnessTest extends MatchingIntegrationTestCase
{
    /** Spec 12 §9's per-provider regression-gate thresholds. */
    private const array THRESHOLDS = [
        'spotify' => ['autoAcceptPrecision' => 0.95, 'silentErrorRate' => 0.03],
        'youtube' => ['autoAcceptPrecision' => 0.90, 'silentErrorRate' => 0.05],
    ];

    private const string DEFAULT_PROVIDER_KEY = 'spotify';

    private ProviderTokens $tokens;

    /** @var array<string, mixed> */
    private array $manifest;

    /** @var array<string, \App\Entity\Band> keyed by raw band name, so the same band across catalog fixture entries is persisted once. */
    private array $catalogBands = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetMatchingRedis();
        $this->resetMatchingDatabase();
        $this->tokens = new ProviderTokens('access', null, new \DateTimeImmutable('+1 hour'), []);
        $this->manifest = MatchingFixtureManifest::load();
    }

    /**
     * D-122's hard gate: non-song precision must be 1.00 — no real song may ever be silently
     * reclassified as a performance artifact. Runs the FULL `TrackMatcher::match()` cascade (not
     * `NonSongClassifier` directly) so a regression anywhere in Tier 0 — not just the classifier's
     * own logic — trips this.
     */
    public function testNonSongClassificationPrecisionMeetsTheHardRequirement(): void
    {
        $entries = [
            ...self::structuralEntries($this->manifest, 'nonSong'),
            ...self::structuralEntries($this->manifest, 'songDespiteLexiconTerm'),
        ];
        self::assertNotEmpty($entries, 'fixture manifest has no non-song entries to evaluate');

        $skipDecisions = 0;
        $correctSkipDecisions = 0;

        $bands = [];
        foreach ($entries as $entry) {
            $bandName = (string) $entry['band'];
            $band = $bands[$bandName] ??= $this->persistBand($bandName);
            $setlist = $this->persistSetlist($band, self::shortFixtureId((string) $entry['id']));
            $song = $this->persistSong(
                $setlist,
                0,
                (string) $entry['title'],
                isTape: (bool) ($entry['isTape'] ?? false),
            );

            $provider = new ScriptedMatchingProvider([]);
            $results = $this->matcher()->match($song, (string) $entry['band'], (bool) $entry['isSetBoundary'], $provider, $this->tokens);

            $actualSkipped = MatchOutcome::Skipped === $results[0]->outcome;
            $expectedSkipped = (bool) $entry['expectedSkipped'];

            self::assertSame(
                $expectedSkipped,
                $actualSkipped,
                \sprintf('fixture "%s" (%s): expected skipped=%s, got skipped=%s — %s', $entry['id'], $entry['title'], $expectedSkipped ? 'true' : 'false', $actualSkipped ? 'true' : 'false', $entry['reason'] ?? ''),
            );

            if ($actualSkipped) {
                ++$skipDecisions;
                if ($expectedSkipped) {
                    ++$correctSkipDecisions;
                }
            }
        }

        $precision = 0 === $skipDecisions ? 1.0 : $correctSkipDecisions / (float) $skipDecisions;
        self::assertEqualsWithDelta(
            1.0,
            $precision,
            1e-9,
            \sprintf('non-song precision is %.4f over %d skip decisions — spec 12 §9 requires exactly 1.00 (hard)', $precision, $skipDecisions),
        );
    }

    /**
     * The text-splitting half of medley/segue handling (spec 12 §9 fixture 7) — catalog-independent
     * by construction, so it is fully armed. Whether each resulting segment then resolves to the
     * RIGHT track is the catalog-dependent question `testAutoAcceptPrecisionCoverageAndSilentError
     * RateGate()` answers once armed.
     */
    public function testMedleySegmentationMatchesTheFrozenFixtures(): void
    {
        $entries = self::structuralEntries($this->manifest, 'medley');
        self::assertNotEmpty($entries, 'fixture manifest has no medley entries to evaluate');

        $splitter = self::getContainer()->get(MedleySplitter::class);

        foreach ($entries as $entry) {
            /** @var list<string> $expected */
            $expected = $entry['expectedSegments'];
            $actual = $splitter->split((string) $entry['title']);

            self::assertSame(
                $expected,
                $actual,
                \sprintf('fixture "%s" (%s): %s', $entry['id'], $entry['title'], $entry['reason'] ?? ''),
            );
        }
    }

    /**
     * `SongNormalizer`'s N0-N8 pipeline is a pure function of the input text — its output on a given
     * title is a fact about the code, not a catalog judgement call, so asserting it needs no human
     * labeller or live credentials to be honest (spec 12 §9 fixture 5/6).
     */
    public function testDiacriticAndArticleNormalizationMatchesTheFrozenFixtures(): void
    {
        $entries = self::structuralEntries($this->manifest, 'normalization');
        self::assertNotEmpty($entries, 'fixture manifest has no normalization entries to evaluate');

        $normalizer = self::getContainer()->get(SongNormalizer::class);

        foreach ($entries as $entry) {
            $normalized = $normalizer->normalize((string) $entry['title']);

            self::assertSame(
                (string) $entry['expectedComparisonCore'],
                $normalized->comparisonCore,
                \sprintf('fixture "%s" (%s): comparisonCore — %s', $entry['id'], $entry['title'], $entry['reason'] ?? ''),
            );
            self::assertSame(
                $entry['expectedTokens'],
                $normalized->tokens,
                \sprintf('fixture "%s" (%s): tokens — %s', $entry['id'], $entry['title'], $entry['reason'] ?? ''),
            );
        }
    }

    /**
     * Spec 12 §9's primary gate. Wired up end-to-end: given catalog fixtures, this runs the full
     * cascade against recorded provider responses and fails the build below threshold, exactly as
     * D-122/D-123 require. Given NO catalog fixtures — the current state, see this class's docblock
     * — it skips loudly rather than passing silently or fabricating a result.
     */
    public function testAutoAcceptPrecisionCoverageAndSilentErrorRateGate(): void
    {
        /** @var list<array<string, mixed>> $catalogEntries */
        $catalogEntries = $this->manifest['catalog'] ?? [];

        if ([] === $catalogEntries) {
            self::markTestSkipped(
                'No catalog fixtures under tests/Fixtures/matching/catalog/ — auto-accept precision, '
                .'coverage and silent-error rate cannot be computed without real provider search '
                .'responses and hand-labelled expected track ids. See this class\'s docblock for the '
                .'exact capture checklist (Spotify credentials, the 8 setlists, the ~200-entry '
                .'labelling pass) required to arm this gate.',
            );
        }

        $report = $this->runCatalogFixtures($catalogEntries);
        $this->writeReport($report, catalogArmed: true);

        foreach ($report['catalog']['byProvider'] as $providerKey => $metrics) {
            $thresholds = self::THRESHOLDS[$providerKey] ?? self::THRESHOLDS[self::DEFAULT_PROVIDER_KEY];

            self::assertGreaterThanOrEqual(
                $thresholds['autoAcceptPrecision'],
                $metrics['autoAcceptPrecision'],
                \sprintf('%s auto-accept precision %.4f is below the %.2f gate (D-122/D-123)', $providerKey, $metrics['autoAcceptPrecision'], $thresholds['autoAcceptPrecision']),
            );
            self::assertLessThanOrEqual(
                $thresholds['silentErrorRate'],
                $metrics['silentErrorRate'],
                \sprintf('%s silent-error rate %.4f is above the %.2f gate (D-122)', $providerKey, $metrics['silentErrorRate'], $thresholds['silentErrorRate']),
            );
            self::assertEqualsWithDelta(
                1.0,
                $metrics['nonSongPrecision'],
                1e-9,
                \sprintf('%s catalog-scope non-song precision is %.4f — spec 12 §9 requires exactly 1.00 (hard)', $providerKey, $metrics['nonSongPrecision']),
            );
        }
    }

    /**
     * Writes `var/matching-report.json` (spec 12 §9 point 4) unconditionally — even while the
     * catalog gate above is unarmed — so the report format and its "catalog unarmed" marker are
     * themselves exercised, and so a future capture pass has a report to diff against from day one.
     */
    public function testWritesMachineReadableQualityReport(): void
    {
        /** @var list<array<string, mixed>> $catalogEntries */
        $catalogEntries = $this->manifest['catalog'] ?? [];

        $report = [] === $catalogEntries
            ? [
                'catalog' => [
                    'armed' => false,
                    'reason' => 'no fixtures under tests/Fixtures/matching/catalog/ — see MatchingQualityHarnessTest\'s docblock',
                    'byProvider' => [],
                ],
            ]
            : $this->runCatalogFixtures($catalogEntries);

        $path = $this->writeReport($report, catalogArmed: [] !== $catalogEntries);

        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('generatedAt', $decoded);
        self::assertArrayHasKey('algorithmVersion', $decoded);
        self::assertArrayHasKey('structural', $decoded);
        self::assertArrayHasKey('catalog', $decoded);
    }

    // -------------------------------------------------------------------------------------------
    // Catalog cascade (wired up, currently exercised only once tests/Fixtures/matching/catalog/ is
    // populated — see testAutoAcceptPrecisionCoverageAndSilentErrorRateGate()).
    // -------------------------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $catalogEntries
     *
     * @return array<string, mixed>
     */
    private function runCatalogFixtures(array $catalogEntries): array
    {
        /** @var array<string, array{autoAcceptCorrect: int, autoAcceptTotal: int, coverageCorrect: int, matchable: int, wrongAutoAccept: int, skipCorrect: int, skipTotal: int, songs: list<array<string, mixed>>}> $byProvider */
        $byProvider = [];

        foreach ($catalogEntries as $setlistEntry) {
            $providerKey = (string) $setlistEntry['provider'];
            $byProvider[$providerKey] ??= [
                'autoAcceptCorrect' => 0,
                'autoAcceptTotal' => 0,
                'coverageCorrect' => 0,
                'matchable' => 0,
                'wrongAutoAccept' => 0,
                'skipCorrect' => 0,
                'skipTotal' => 0,
                'songs' => [],
            ];

            $bandName = (string) $setlistEntry['band'];
            $band = $this->catalogBands[$bandName] ??= $this->persistBand($bandName);
            $setlist = $this->persistSetlist($band, self::shortFixtureId((string) $setlistEntry['id']));

            /** @var list<array<string, mixed>> $songs */
            $songs = $setlistEntry['songs'];
            foreach ($songs as $position => $songFixture) {
                $song = $this->persistSong(
                    $setlist,
                    $position,
                    (string) $songFixture['title'],
                    coverOfName: $songFixture['coverOfName'] ?? null,
                    isTape: (bool) ($songFixture['isTape'] ?? false),
                );

                $candidates = $this->loadCandidates((string) ($songFixture['searchResponseFixture'] ?? ''));
                $provider = new ScriptedMatchingProvider($candidates);

                $results = $this->matcher()->match($song, (string) $setlistEntry['band'], (bool) ($songFixture['isSetBoundary'] ?? false), $provider, $this->tokens);

                /** @var array<string, mixed> $expected */
                $expected = $songFixture['expected'];
                $expectedOutcome = (string) $expected['outcome'];

                foreach ($results as $result) {
                    $this->accumulateCatalogMetrics($byProvider[$providerKey], $result, $expected, $expectedOutcome, (string) $songFixture['title']);
                }
            }
        }

        $metrics = [];
        foreach ($byProvider as $providerKey => $totals) {
            $matchable = $totals['matchable'];
            $metrics[$providerKey] = [
                'autoAcceptPrecision' => 0 === $totals['autoAcceptTotal'] ? 1.0 : $totals['autoAcceptCorrect'] / $totals['autoAcceptTotal'],
                'coverage' => 0 === $matchable ? 0.0 : $totals['coverageCorrect'] / $matchable,
                'silentErrorRate' => 0 === $matchable ? 0.0 : $totals['wrongAutoAccept'] / $matchable,
                'nonSongPrecision' => 0 === $totals['skipTotal'] ? 1.0 : $totals['skipCorrect'] / $totals['skipTotal'],
                'songs' => $totals['songs'],
            ];
        }

        return [
            'catalog' => [
                'armed' => true,
                'byProvider' => $metrics,
            ],
        ];
    }

    /**
     * @param array{autoAcceptCorrect: int, autoAcceptTotal: int, coverageCorrect: int, matchable: int, wrongAutoAccept: int, skipCorrect: int, skipTotal: int, songs: list<array<string, mixed>>} $totals
     * @param array<string, mixed>                                                                                                                                                               $expected
     */
    private function accumulateCatalogMetrics(array &$totals, MatchResult $result, array $expected, string $expectedOutcome, string $title): void
    {
        $actualOutcome = $result->outcome->value;

        if (MatchOutcome::Skipped === $result->outcome) {
            ++$totals['skipTotal'];
            if ('skipped' === $expectedOutcome) {
                ++$totals['skipCorrect'];
            }

            return;
        }

        $isMatchable = 'skipped' !== $expectedOutcome;
        if ($isMatchable) {
            ++$totals['matchable'];
        }

        $isCorrectTrack = ($expected['trackId'] ?? null) === $result->providerTrackId;

        if (MatchOutcome::Matched === $result->outcome) {
            ++$totals['autoAcceptTotal'];
            if ($isCorrectTrack) {
                ++$totals['autoAcceptCorrect'];
                ++$totals['coverageCorrect'];
            } else {
                ++$totals['wrongAutoAccept'];
            }
        } elseif (MatchOutcome::MatchedLowConfidence === $result->outcome && $isCorrectTrack) {
            ++$totals['coverageCorrect'];
        }

        $totals['songs'][] = [
            'title' => $title,
            'expectedOutcome' => $expectedOutcome,
            'actualOutcome' => $actualOutcome,
            'expectedTrackId' => $expected['trackId'] ?? null,
            'actualTrackId' => $result->providerTrackId,
            'confidence' => $result->confidence,
            'correct' => $isCorrectTrack || ($expectedOutcome === $actualOutcome && null === ($expected['trackId'] ?? null)),
        ];
    }

    /** @return list<TrackCandidate> */
    private function loadCandidates(string $relativePath): array
    {
        if ('' === $relativePath) {
            return [];
        }

        $path = MatchingFixtureManifest::catalogDir().'/'.$relativePath;
        \assert(is_file($path), \sprintf('missing catalog fixture: %s', $path));

        /** @var list<array<string, mixed>> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

        return array_map(self::catalogFixtureToTrackCandidate(...), $decoded);
    }

    /**
     * The exact field mapping a capture pass's provider search response JSON must follow — one
     * object per candidate, matching `TrackCandidate`'s constructor.
     *
     * @param array<string, mixed> $row
     */
    private static function catalogFixtureToTrackCandidate(array $row): TrackCandidate
    {
        return new TrackCandidate(
            providerTrackId: (string) $row['providerTrackId'],
            title: (string) $row['title'],
            artist: (string) $row['artist'],
            album: $row['album'] ?? null,
            durationMs: (int) ($row['durationMs'] ?? 0),
            isLive: (bool) ($row['isLive'] ?? false),
            isCover: (bool) ($row['isCover'] ?? false),
            confidence: (float) ($row['confidence'] ?? 0.0),
            artistAuthority: isset($row['artistAuthority']) ? ArtistAuthority::from((string) $row['artistAuthority']) : ArtistAuthority::Unknown,
            albumType: isset($row['albumType']) ? AlbumType::from((string) $row['albumType']) : null,
            popularity: isset($row['popularity']) ? (float) $row['popularity'] : null,
            isrc: $row['isrc'] ?? null,
            providerRank: (int) ($row['providerRank'] ?? 0),
        );
    }

    /** @param array<string, mixed> $catalogReport */
    private function writeReport(array $catalogReport, bool $catalogArmed): string
    {
        $registry = self::getContainer()->get(MatchProfileRegistry::class);

        $report = [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'algorithmVersion' => $registry->algorithmVersion(),
            'structural' => [
                'nonSong' => self::structuralEntries($this->manifest, 'nonSong'),
                'songDespiteLexiconTerm' => self::structuralEntries($this->manifest, 'songDespiteLexiconTerm'),
                'medley' => self::structuralEntries($this->manifest, 'medley'),
                'normalization' => self::structuralEntries($this->manifest, 'normalization'),
            ],
            'catalog' => $catalogReport['catalog'],
        ];

        $dir = \dirname(__DIR__, 2).'/var';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $path = $dir.'/matching-report.json';

        file_put_contents($path, json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<array<string, mixed>>
     */
    private static function structuralEntries(array $manifest, string $key): array
    {
        /** @var array<string, mixed> $structural */
        $structural = $manifest['structural'] ?? [];

        /** @var list<array<string, mixed>> $entries */
        $entries = $structural[$key] ?? [];

        return $entries;
    }

    /** `Setlist::$setlistfmId` is `varchar(32)` — fixture ids are longer and human-readable, so a stable short hash stands in for it here. */
    private static function shortFixtureId(string $fixtureId): string
    {
        return substr(hash('xxh32', $fixtureId), 0, 8).'-'.substr(hash('sha256', $fixtureId), 0, 16);
    }

    private function matcher(): TrackMatcher
    {
        $matcher = self::getContainer()->get(TrackMatcher::class);

        return $matcher;
    }
}
