<?php

declare(strict_types=1);

namespace App\Service\Matching;

use App\Service\Matching\Model\NormalizedSong;
use App\Service\Matching\Model\Qualifier;
use App\Service\Matching\Model\QualifierKind;

/**
 * The N0-N8 transform pipeline (spec 12 §1). Comparison-only: the raw title, never this class's
 * output, is what goes to the provider (D-107). Pure, static-shaped, no database involvement — the
 * artist side of every comparison instead reuses `App\Service\Concert\BandResolver::normalize()`
 * verbatim (D-106); this class is deliberately not shared with it because song titles keep their
 * leading article (N6) while band names do not.
 */
final class SongNormalizer
{
    private const array LIGATURE_FOLD = [
        'æ' => 'ae', 'Æ' => 'ae',
        'ø' => 'o', 'Ø' => 'o',
        'ß' => 'ss',
        'ð' => 'd', 'Ð' => 'd',
        'þ' => 'th', 'Þ' => 'th',
        'ł' => 'l', 'Ł' => 'l',
        'đ' => 'd', 'Đ' => 'd',
    ];

    /** @var array<string, string> curly/typographic punctuation unified to a plain ASCII form (N3). */
    private const array PUNCTUATION_UNIFY = [
        '’' => "'", '‘' => "'", '‛' => "'", '´' => "'", '`' => "'",
        '“' => '"', '”' => '"', '„' => '"',
        '–' => '-', '—' => '-', '−' => '-', '‒' => '-',
        '…' => '...',
    ];

    /** N4's version lexicon — the whole segment (after trimming/lowering) matches one of these. */
    private const array VERSION_TAGS = [
        'live' => 'live', 'acoustic' => 'acoustic', 'unplugged' => 'acoustic',
        'demo' => 'demo', 'alternate' => 'demo', 'alternate version' => 'demo',
        'remaster' => 'studio', 'remastered' => 'studio',
        'radio edit' => 'radio_edit', 'single version' => 'studio', 'album version' => 'studio',
        'extended' => 'remix', 'remix' => 'remix', 'instrumental' => 'instrumental',
        'mono' => 'studio', 'stereo' => 'studio', 'edit' => 'radio_edit',
        'deluxe' => 'studio', 'bonus track' => 'studio',
    ];

    private const array FEATURED_MARKERS = ['feat.', 'feat', 'ft.', 'ft', 'featuring', 'with', 'w/', 'con'];

    /** @var list<string> N6 stop tokens — present in the token-set metric at 0.25 weight, not 1.0. */
    public const array STOP_TOKENS = ['a', 'an', 'the', 'el', 'la', 'los', 'las', 'un', 'una', 'de', 'del', 'y', 'and', 'of', 'in', 'on', 'to', 'for'];

    public function normalize(string $rawTitle): NormalizedSong
    {
        // N0
        $value = trim($rawTitle);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        // N4 — extract parentheticals/trailing " - X" segments from the RAW string first, so N5's
        // positional featured-credit rule and the version lexicon see them before N7 destroys the
        // delimiters. Each extracted segment is independently run through N1-N3/N7 for comparison.
        [$core, $rawQualifierSegments] = self::extractSegments($value);

        $qualifiers = [];
        $featuredArtists = [];
        $hasVersion = false;
        $versionTag = null;

        foreach ($rawQualifierSegments as $segment) {
            $classified = self::classifySegment($segment);
            $qualifiers[] = $classified;

            if (QualifierKind::Version === $classified->kind) {
                $hasVersion = true;
                $versionTag ??= $classified->versionTag;
            } elseif (QualifierKind::FeaturedCredit === $classified->kind) {
                $featuredArtists[] = self::stripFeaturedMarker($classified->rawSegment);
            } else {
                // TitleContinuation: folded back into the core, space-joined (N4's default).
                $core .= ' '.self::foldCore($classified->rawSegment);
            }
        }

        $core = self::foldCore($core);
        $core = (string) preg_replace('/\s+/u', ' ', $core);
        $core = trim($core);

        $tokens = '' === $core ? [] : explode(' ', $core);

        return new NormalizedSong($core, $tokens, $qualifiers, $featuredArtists, $hasVersion, $versionTag);
    }

    /**
     * N1/N1b/N2/N3/N7 applied to one segment (title core or an extracted continuation), in order.
     * N6 (keep leading article) needs no code — it is simply the absence of an article-stripping step.
     */
    private static function foldCore(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_KD);
        if (false !== $decomposed) {
            $value = $decomposed;
        }
        $value = (string) preg_replace('/\p{Mn}/u', '', $value); // N1

        $value = strtr($value, self::LIGATURE_FOLD); // N1b

        $value = mb_strtolower($value, 'UTF-8'); // N2

        $value = strtr($value, self::PUNCTUATION_UNIFY); // N3
        $value = (string) preg_replace('/(?<=\s)&(?=\s)/u', 'and', $value);
        $value = (string) preg_replace('/(?<=\s)\+(?=\s)/u', 'and', $value);

        // N7, in two steps rather than one. Apostrophes are DELETED (`rockin'` → `rockin`,
        // `don't` → `dont`), because both sides of the comparison elide them the same way. Every
        // other separator becomes a SPACE (`freeze-out` → `freeze out`, `untitled #1` → `untitled 1`):
        // deleting those instead would weld two words into one token, so a catalog
        // `Tenth Avenue Freeze-Out` would tokenize as `freezeout` while a setlist entry written
        // `Tenth Avenue Freeze Out` tokenizes as two — and the token-set half of the metric would
        // score a perfect pair as a mismatch.
        $value = str_replace("'", '', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);

        return (string) preg_replace('/\s+/u', ' ', trim($value)); // N8
    }

    /**
     * N4: pulls every `(...)`, `[...]` and trailing ` - ...` segment out of the raw title, returning
     * [$remainingCore, list<rawSegmentText>].
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function extractSegments(string $rawTitle): array
    {
        $segments = [];
        $core = $rawTitle;

        if (preg_match_all('/[\(\[]([^\(\)\[\]]+)[\)\]]/u', $core, $matches)) {
            foreach ($matches[1] as $inner) {
                $segments[] = trim($inner);
            }
            $core = (string) preg_replace('/[\(\[][^\(\)\[\]]+[\)\]]/u', ' ', $core);
        }

        // Trailing " - Segment" (only as a suffix, never mid-title — N5's positional rule).
        if (preg_match('/^(.*\S)\s+-\s+(\S.*)$/u', trim($core), $m)) {
            $core = $m[1];
            $segments[] = trim($m[2]);
        }

        $core = (string) preg_replace('/\s+/u', ' ', trim($core));

        return [$core, $segments];
    }

    private static function classifySegment(string $segment): Qualifier
    {
        $normalizedSegment = mb_strtolower(trim($segment), 'UTF-8');

        // Version qualifier: whole-segment match, or a "live at ..."/"live in ..." prefix form.
        if (isset(self::VERSION_TAGS[$normalizedSegment])) {
            return new Qualifier(QualifierKind::Version, $segment, self::VERSION_TAGS[$normalizedSegment]);
        }
        if (preg_match('/^live\s+(at|in)\s+/u', $normalizedSegment)) {
            return new Qualifier(QualifierKind::Version, $segment, 'live');
        }
        if (preg_match('/^\d{4}\s+remaster(ed)?$/u', $normalizedSegment)) {
            return new Qualifier(QualifierKind::Version, $segment, 'studio');
        }

        // Featured credit: must START with an N5 marker.
        foreach (self::FEATURED_MARKERS as $marker) {
            if (str_starts_with($normalizedSegment, $marker.' ') || $normalizedSegment === $marker) {
                return new Qualifier(QualifierKind::FeaturedCredit, $segment);
            }
        }

        // Default: TitleContinuation (N4's deliberate default).
        return new Qualifier(QualifierKind::TitleContinuation, $segment);
    }

    private static function stripFeaturedMarker(string $segment): string
    {
        $trimmed = trim($segment);
        foreach (self::FEATURED_MARKERS as $marker) {
            if (0 === stripos($trimmed, $marker)) {
                return trim(substr($trimmed, \strlen($marker)));
            }
        }

        return $trimmed;
    }
}
