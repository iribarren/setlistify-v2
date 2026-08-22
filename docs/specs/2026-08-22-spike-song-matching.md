# SPIKE — Song Matching: from a setlist.fm entry to a playable track

| | |
|---|---|
| **Spec ID** | `2026-08-22-spike-song-matching` |
| **Backlog prompt** | `docs/prompts/12-spike-song-matching.md` |
| **Command** | `/spec spike-song-matching` |
| **Primary agent** | `backend-engineer` |
| **Type** | **SPIKE — a recommendation, not an implementation.** No branch, no code, no migration |
| **Depends on** | `09` — setlist.fm integration (merged) · `10` — streaming port and account linking (merged) · `11` — backoffice provider configuration (merged) |
| **Implemented by** | `14` — playlist fast mode backend · `17` — normal mode · `18` — YouTube adapter |
| **Status** | **Approved** |

---

## Overview

### The recommendation, stated first

**Simple plus honest confidence beats clever.** The prompt asks whether the temptation to
over-engineer this should be resisted, and the answer this document reaches, after working the
arithmetic, is: yes, emphatically.

The reason is not modesty about algorithms. It is that the expensive resource here is not CPU, it is
**provider calls** — one YouTube search costs 1% of the application's entire daily quota — and no
amount of algorithmic sophistication buys back a call that was never worth making. Within a single
search's result set of twenty-odd candidates, the gap between a careful weighted heuristic and a
genuinely clever one is small; the gap between *either* of those and **admitting when you are not
sure** is enormous. A playlist that silently contains a tribute-band recording of "Sæglópur" is worse
than a playlist that says "we couldn't confidently find this one" — because the first destroys trust
in every other track on the list, and the second is the product being honest about a genuinely hard
input.

So the design below is deliberately unexciting in its core — normalize, one search, score the
candidates on a weighted formula, band the result into three outcomes — and puts its effort into the
three places that actually decide whether the product feels good:

1. **Normalization that extracts rather than strips**, so `Untitled #1 (Vaka)` does not become
   `untitled 1` and collide with `Untitled #2`.
2. **A confidence formula that degrades gracefully** when signals are missing (which is the normal
   case — setlist.fm supplies no duration at all), and a hard **artist gate** so the single most
   damaging error class, *right title, wrong artist*, cannot be auto-accepted.
3. **Three outcome bands, meaning different things in each mode**, so Fast mode still includes a
   flagged uncertain match rather than dropping it, and Normal mode asks about three songs rather
   than twenty-five.

### What this document is

Prompt 09 ends at *"here are the songs setlist.fm recorded, in playing order"* — real rows in
`App\Entity\Song`, with `title`, `coverOfName`, `withName`, `info` and `isTape` preserved verbatim
(D-60, AC-4.3). Prompt 10 ends at *"the port can search a track"* —
`App\Service\Streaming\StreamingProviderInterface::searchTrack()` returning
`list<App\Service\Streaming\Model\TrackCandidate>` with a confidence score that its own docblock
calls **deliberately provisional** (D-83, AC-11.2), computed today by
`App\Service\Streaming\Spotify\SpotifyTrackMapper::naiveConfidence()`.

This document specifies what replaces that method's body, and everything around it. It is complete
enough to implement without further research; prompt 14 builds it, prompt 17 uses its middle band,
prompt 18 re-calibrates it.

### Load-bearing rules this spec does not reverse

| Rule | Source | How this design honours it |
|---|---|---|
| The streaming port is the only way to reach a provider | `CLAUDE.md`, `docs/architecture.md` §4 | The matcher type-hints `StreamingProviderInterface` only. Provider-specific signal extraction lives inside each adapter directory; the scorer never sees a provider name (D-118) |
| No `Spotify`/`YouTube` symbol outside its adapter directory | `CLAUDE.md`, D-82 | `App\Service\Matching\` contains no provider symbol and no provider key literal. Per-provider *configuration* is keyed by `StreamingProviderInterface::key()`, which is a runtime string (D-110) |
| setlist.fm responses are always cached; `SetlistGateway` is the only door | `CLAUDE.md`, D-58 | **Matching spends zero setlist.fm budget.** It reads `Setlist`/`Song` rows that already exist; it holds no reference to `SetlistGateway` and needs none (§7) |
| Provider state is read at runtime via `ProviderRegistry` | `CLAUDE.md`, D-89 | The matcher is handed an adapter by the caller; the caller resolves it through `App\Service\Provider\ProviderRegistry`. The matcher never selects a provider itself (D-118) |
| Playlist generation degrades, it does not fail | `CLAUDE.md` | Every special case in §5 has a defined non-error outcome. Zero candidates is an empty array, not an exception (AC-11.6). The reject band produces a report line, never a failed job |
| The backoffice edits behaviour, never credentials | `CLAUDE.md` | Thresholds are deploy-gated configuration, **not** `ProviderSetting` rows — argued in D-110, and it is a deliberate departure from "put the knob in the backoffice" |
| The port is frozen at nine methods | D-71 | Nothing here adds a method. `TrackCandidate` gains provider-agnostic *fields* — a shared value object outside every adapter, which D-71 does not freeze (D-119) |
| CI runs no integration tests against real external APIs | D-2, D-70, D-85 | The evaluation harness (§9) runs entirely on committed fixtures. Provider search responses are recorded once, by hand |

### Existing groundwork this design builds on, not around

| Already in place | Where | Used for |
|---|---|---|
| `App\Entity\Song` — `title`, `position`, `setLabel`, `coverOfName`, `coverOfMbid`, `withName`, `info`, `isTape` | `backend/src/Entity/Song.php` | Every input signal the matcher has. `isTape` is the free, language-independent non-song detector (D-116); `coverOfName` decides the expected artist (D-113) |
| `App\Service\Concert\BandResolver::normalize()` | `backend/src/Service/Concert/BandResolver.php` | Reused **verbatim for the artist side** of the comparison, and deliberately **not** reused for titles (D-106) |
| `App\Service\Streaming\Model\SongQuery` (`songTitle`, `bandName`) | `backend/src/Service/Streaming/Model/` | The matcher's request to the port. Unchanged in shape |
| `App\Service\Streaming\Model\TrackCandidate` | same | The candidate set the scorer works over. Gains generic signal fields (D-119) |
| `App\Service\Streaming\Spotify\SpotifyTrackMapper::naiveConfidence()` | `backend/src/Service/Streaming/Spotify/` | The method D-83 promised prompt 12 would replace. §2 explains precisely why its `levenshtein()` is wrong |
| `App\Service\Provider\ProviderRegistry` / `ProviderConfig` | `backend/src/Service/Provider/` | The caller's runtime provider selection; the matcher inherits the choice |
| `SetlistCacheEntry` verbatim JSONB payload (D-60) | prompt 09 | Re-deriving song fields this spec did not anticipate without re-spending setlist.fm budget |
| Redis, `symfony/lock`, `symfony/messenger` | `compose.yaml`, `composer.json` | The resolution cache's volatile tier and the async pipeline prompt 13 designs |

## Goals

| Goal | Success looks like |
|---|---|
| A song becomes a track, or an honest explanation | Every `Song` row produces exactly one decided outcome: `matched`, `matched_low_confidence`, `skipped`, `not_found` or `region_restricted`. There is no fifth state and no silent drop |
| Silent errors are rare, misses are cheap | Precision on auto-accepted matches ≥ **0.95** on the fixture set. A wrong silent pick costs more than an honest miss, and the metric says so (§9) |
| Matching costs one provider call per song, and often zero | One `searchTrack()` per unresolved song; a cached resolution costs none. No speculative second search (D-120) |
| The formula degrades when signals are absent | Missing duration, missing album type, missing popularity all renormalize the weight vector rather than scoring zero (D-109) |
| A tuning change can be proven better | The fixture harness produces a numeric report; a change that lowers auto-accept precision fails the build (§9, D-122) |
| The same algorithm serves both providers | One `TrackMatcher`, two calibration files, provider-specific signal extraction behind the port (D-118) |
| The scarcest resource is respected | On YouTube, a cold-cache 25-song generation costs ~38% of the application's daily quota. The cache is not an optimization, it is the viability condition (§7) |
| Nothing here leaks a provider | `App\Service\Matching\` contains no provider symbol; the architecture test (AC-9.4) stays green |

## User Stories

A spike's stories are about the readers of the document, not the users of the product. Each
acceptance criterion is a property of *this document*, checkable by reading it.

### US-1 — Implement matching without further research

> As the **backend engineer implementing prompt 14**, I want the algorithm specified to the level of
> transforms, metrics, weights and thresholds, so that I write code rather than make decisions.

**Acceptance criteria**

- **AC-1.1** The normalization pipeline is an ordered list of named transforms (§1), each with its
  input, output and failure cost, and a worked example table on real setlist.fm-style titles.
- **AC-1.2** The similarity metric is named, defined mathematically, and its multibyte behaviour is
  specified (§2). Rejected alternatives are named with the reason.
- **AC-1.3** The confidence formula is written out with every signal, its weight, and its
  normalization to [0,1] (§3).
- **AC-1.4** Thresholds are numeric, justified, and their location in configuration is decided
  (D-110).
- **AC-1.5** The service shape — class names, namespaces, what each owns — is given (§ Component
  shape), using namespaces consistent with the existing tree.

### US-2 — Know what happens to every weird setlist entry

> As the **backend engineer**, I want a decided behaviour for covers, medleys, snippets, non-songs
> and absent songs, so that I never have to invent one mid-implementation.

**Acceptance criteria**

- **AC-2.1** §5 contains a table with one row per case, naming what reaches the playlist and what
  reaches the report.
- **AC-2.2** The non-song detection strategy is specified and its use of a curated lexicon is argued
  rather than assumed, including the precision requirement that makes it safe (D-116).
- **AC-2.3** No case resolves to "throw" or "fail the job" — consistent with `CLAUDE.md`'s
  degradation rule.
- **AC-2.4** Cover attribution decides the *expected artist*, and that decision is argued against the
  alternative (D-113).

### US-3 — Know whether the studio or the live version is right

> As the **product owner**, I want the studio-vs-live question answered with an argument I can
> disagree with, not deferred to a later prompt.

**Acceptance criteria**

- **AC-3.1** §4 recommends one, with the second-order effects stated (a live album is from a
  different tour, a different lineup, a different decade).
- **AC-3.2** The fallback for songs that exist only as live recordings is specified and does not
  require flipping the default.
- **AC-3.3** Whether the preference is user-configurable is answered, and if so, where the setting
  would live (D-112).

### US-4 — Add YouTube without redesigning matching

> As the **backend engineer implementing prompt 18**, I want to know before I start whether the
> Spotify-tuned numbers apply to YouTube.

**Acceptance criteria**

- **AC-4.1** §6 states, without "TBD", whether prompt 18 needs its own calibration.
- **AC-4.2** The seam is specified: which part of matching is provider-agnostic and which belongs
  inside an adapter directory (D-118).
- **AC-4.3** The strongest available per-provider signal is named for each provider, and the
  provider-agnostic field that carries it is named (D-119).
- **AC-4.4** Initial YouTube threshold and weight guesses are given so prompt 18 starts from a number
  rather than from zero.

### US-5 — Spend the budget deliberately

> As the **product owner**, I want the provider-call arithmetic for one generation, so that I know
> what a playlist actually costs before users exist.

**Acceptance criteria**

- **AC-5.1** §7 gives the call count for a 25-song setlist per provider, cold and warm cache.
- **AC-5.2** The YouTube quota arithmetic is worked explicitly, including playlist inserts, not only
  searches.
- **AC-5.3** Whether batching is available is answered per provider, factually.
- **AC-5.4** The matching-side CPU cost is estimated in milliseconds, per song and per setlist, and
  set against the network cost.
- **AC-5.5** setlist.fm's 1,440/day cap is addressed — specifically, that matching spends none of it.

### US-6 — Prove a future change is an improvement

> As the **product owner**, I want match quality to be a number on a frozen fixture set, so that the
> next tweak is evidence-backed rather than plausible-sounding.

**Acceptance criteria**

- **AC-6.1** §9 sketches a fixture set from real, nameable setlists, including a cover, a medley, a
  non-song entry, a diacritic/non-Latin title, an abbreviation and a song absent from the catalog.
- **AC-6.2** The pass/fail metric is numeric, and the document says which metric matters most and
  why.
- **AC-6.3** Target numbers for the first implementation are given, separately per provider.
- **AC-6.4** The mechanism by which a future change proves itself is specified (D-122).

---

## Technical Approach

### Component shape

Everything provider-agnostic lands in one new namespace. Nothing in it may name a provider.

```
backend/src/Service/Matching/                 ← NEW, provider-agnostic, no provider symbol
  SongNormalizer.php            ← §1. Song title → NormalizedSong (a struct, not a string)
  Model/
    NormalizedSong.php          ← queryTitle, comparisonCore, tokens[], qualifiers[],
                                   featuredArtists[], segments[]
    Qualifier.php               ← enum: Version | FeaturedCredit | TitleContinuation
    MatchOutcome.php            ← enum: Matched | MatchedLowConfidence | Skipped
                                   | NotFound | RegionRestricted
    MatchResult.php             ← outcome, TrackCandidate|null, confidence, reasonCode, signals
  Similarity/
    TitleSimilarity.php         ← §2. trigram Dice + weighted token-set Jaccard, code-point safe
    ArtistSimilarity.php        ← wraps BandResolver::normalize() (D-106)
  MatchConfidence.php           ← §3. the weighted formula + the artist gate
  MatchProfile.php              ← per-provider weight vector + thresholds, loaded from config
  NonSongClassifier.php         ← §5. isTape → lexicon → advisory tertiary signal
  TrackMatcher.php              ← the cascade (§2), the only public entry point
  Cache/
    TrackResolutionStore.php    ← §8. Redis read-through over a Doctrine table

backend/src/Entity/TrackResolution.php        ← NEW, the durable resolution cache (D-121)

backend/config/matching/
  non_song_terms.yaml           ← D-116: data, not code
  profiles.yaml                 ← D-110: per-provider weights and thresholds
```

Inside each adapter directory (unchanged rule, D-82):

```
backend/src/Service/Streaming/<Provider>/
  <Provider>TrackMapper.php     ← already exists for the reference adapter. Gains the job of
                                  populating the generic signal fields on TrackCandidate (D-119),
                                  and loses naiveConfidence() entirely (D-83's promise redeemed)
  <Provider>QueryBuilder.php    ← builds the provider's search query string from a SongQuery
```

`TrackMatcher` is the only public entry point, mirroring `SetlistGateway`'s single-door shape (D-58)
for the same reason: a rule is only as strong as its weakest caller.

---

### 1. Normalization pipeline

Normalization exists for **comparison only**. The string sent to the provider is the *raw* title
(D-107): every provider's search engine handles diacritics, punctuation and stopwords better than a
regex pipeline does, and stripping them before the query throws away recall we cannot buy back.
Normalization runs on both sides *after* the response arrives — the setlist entry and each candidate
go through the identical pipeline, which is the only way the comparison is meaningful.

#### The ordered transforms

| # | Transform | What it does | Cost when it is wrong |
|---|---|---|---|
| **N0** | Trim, collapse whitespace | `"  Kid   A "` → `"Kid A"` | None. Purely defensive |
| **N1** | Unicode **NFKD**, then strip combining marks (`\p{Mn}`) | `"Sæglópur"` → `"Sæglopur"`; `"Días"` → `"Dias"` | Skip it and every accented title fails to match its unaccented catalog spelling (and vice versa — setlist.fm is crowd-entered, so both spellings exist for the *same* song) |
| **N1b** | **Ligature and special-letter fold** — `æ→ae`, `ø→o`, `ß→ss`, `ð→d`, `þ→th`, `ł→l`, `đ→d` | `"Sæglopur"` → `"Saeglopur"` | NFKD does **not** decompose these — they are single code points with no combining mark, so N1 alone leaves them untouched. Without N1b the entire Icelandic, Nordic and Polish catalog mismatches. Over-folding risk is negligible: no pair of distinct real titles differs only by `æ` vs `ae` |
| **N2** | Case fold (`mb_strtolower`, UTF-8) | `"Saeglopur"` → `"saeglopur"` | Trivially breaks everything if skipped |
| **N3** | **Punctuation unification** — `’‘‛´\``→`'`, `“”„`→`"`, `–—−‒`→`-`, `…`→`...`, `&`→`and`, `+`→`and` (when whitespace-delimited) | `"Rock ’n’ Roll"` → `"rock 'n' roll"`; `"Salt & Pepper"` → `"salt and pepper"` | The curly-vs-straight apostrophe difference is invisible to a human and fatal to a string comparison; setlist.fm and provider catalogs disagree about it constantly. `&`/`and` is a genuine, common divergence between a setlist entry and a catalog title |
| **N4** | **Parenthetical extraction, not stripping** — every `(…)`, `[…]` and trailing ` - …` segment is removed from the core into a classified qualifier list (below) | `"Nothing Else Matters - Live"` → core `nothing else matters`, qualifier `Version: live` | **The single most consequential transform.** Blind stripping turns `"Untitled #1 (Vaka)"` into `untitled 1`, which is then equidistant from `Untitled #2`, `#3`, `#4` — a catastrophic false match on a real, common band. Blind *keeping* makes the studio and live pressings of a song equally distant from the query, so version preference (§4) becomes impossible to express |
| **N5** | **Featured-credit stripping** — `feat.`/`ft.`/`featuring`/`with`/`w/`/`con` plus everything after it, but **only** as a trailing segment or inside an extracted parenthetical, never mid-title | `"Under Pressure (feat. David Bowie)"` → core `under pressure`, featured `David Bowie` | Skip it and a guest credit in the catalog defeats an exact setlist entry. Apply it mid-title and `"Sleeping with the Television On"` becomes `sleeping` — which is why the rule is positional, not a bare substring search. Note that the *setlist* side rarely needs N5: setlist.fm records guests in `Song::$withName`, a real column, so the guest is already out of the title |
| **N6** | **Leading articles are kept** — deliberately **not** stripped (D-106) | `"The End"` stays `the end`; `"Los Días Raros"` stays `los dias raros` | This is the intentional divergence from `BandResolver::normalize()`, which *does* strip them. In band names an article is decoration; in song titles it is load-bearing — `"The End"` vs `"End"`, `"A Day in the Life"` vs `"Day in the Life"`, `"The Wall"` vs `"Wall"` are all distinct real titles. Stripping creates collisions that no later signal can undo. Instead, articles become **stop tokens** in the token-set metric (weight 0.25 rather than 1.0), so their presence or absence costs a little and never decides a match |
| **N7** | Remove remaining characters that are neither letters, digits nor whitespace | `"rock 'n' roll"` → `rock n roll`; `"rockin'"` → `rockin` | Applied symmetrically to both sides after tokenization, so apostrophes vanish on both. Known residual: `"Rock 'n' Roll"` → `rock n roll` vs a catalog `"Rock and Roll"` → `rock and roll` still differ by one token. The trigram half of the metric cushions this (§2); it is not fully solved and is listed as a residual below |
| **N8** | Re-collapse whitespace, trim | — | None |

#### Qualifier classification (the N4 payload)

Each extracted segment is classified against a small lexicon into exactly one of three kinds. The
classification is what makes extraction better than stripping.

| Kind | Matches | Effect |
|---|---|---|
| **Version** | `live`, `acoustic`, `unplugged`, `demo`, `remaster` / `remastered` / `YYYY remaster`, `radio edit`, `single version`, `album version`, `extended`, `mono`, `stereo`, `remix`, `instrumental`, `edit`, `deluxe`, `bonus track`, plus a `live at …` / `live in …` prefix form | Removed from the core; feeds the **version-fit** signal (§3, §4) |
| **FeaturedCredit** | Segment begins with an N5 marker | Removed from the core; feeds `featuredArtists[]`; contributes nothing to scoring except that its absence no longer penalizes |
| **TitleContinuation** | **Everything else** — the default | **Returned to the core**, joined by a space. `"(Come Out Tonight)"`, `"(Vaka)"`, `"(Sittin' On) The Dock of the Bay"` are part of the title, not a version marker |

The default being *continuation* rather than *discard* is the whole point: an unrecognized
parenthetical is far more likely to be part of the title than a version marker we failed to
enumerate, and keeping it is the recoverable error.

#### Worked examples on real setlist.fm-shaped titles

| Raw entry | `comparisonCore` | Qualifiers | Note |
|---|---|---|---|
| `Sæglópur` | `saeglopur` | — | N1 strips the acute, N1b folds `æ`. The catalog spelling `Sæglópur` normalizes identically |
| `Untitled #1 (Vaka)` | `untitled 1 vaka` | — | `(Vaka)` is a TitleContinuation; keeping it is what separates it from `Untitled #2` |
| `Nothing Else Matters (Live)` | `nothing else matters` | `Version: live` | Catalog side. Core now matches the setlist entry exactly; the qualifier drives §4 |
| `Rosalita (Come Out Tonight)` | `rosalita come out tonight` | — | Catalog side. The setlist entry is often just `Rosalita` — see §3's worked example 3 |
| `Tenth Avenue Freeze-Out` | `tenth avenue freeze out` | — | N3 unifies the dash, N7 removes it |
| `Under Pressure (feat. David Bowie)` | `under pressure` | `Featured: David Bowie` | |
| `Los Días Raros` | `los dias raros` | — | Article kept (N6); `dias` after N1 |
| `Rock and Roll / Whole Lotta Love` | segments: `rock and roll`, `whole lotta love` | `medley` | Segmented before N-anything, see §5 |
| `The Ecstasy of Gold` (`isTape = true`) | — | — | Never normalized, never searched (D-116) |
| `Everything In Its Right Place` | `everything in its right place` | — | `in`, `its` are stop tokens; exact core match with the catalog |

#### Reuse of the band normalization rule

`App\Service\Concert\BandResolver::normalize()` already does trim → collapse → NFKD → strip marks →
lowercase → **strip leading article** → strip non-alphanumerics. The relationship to the above is
deliberate and asymmetric (**D-106**):

- **The artist side reuses it verbatim.** Comparing an expected band name to a candidate's credited
  artist *is* band-name normalization; `"Sigur Rós"` vs `"Sigur Ros"` and `"The Rolling Stones"` vs
  `"Rolling Stones"` are exactly the cases it was written for. `ArtistSimilarity` calls it and adds
  nothing.
- **The title side does not.** Three reasons, in order of weight: (a) `normalizedName` is the value
  behind a **UNIQUE index** on `bands.normalized_name`, so the function is effectively frozen —
  changing it means a data migration, and song matching will be tuned repeatedly against the fixture
  set; (b) it strips leading articles, which N6 argues is wrong for titles; (c) it returns a
  `string`, and song normalization must return a structure carrying qualifiers and segments.

Sharing the code would couple every matching tweak to a band-dedup migration. Sharing the *shape* —
NFKD, mark-stripping, lowercase, a pure static function, no database involvement so it stays
re-derivable — costs nothing and is what D-25 anticipated.

#### Known residuals (accepted, recorded, not solved)

| Residual | Example | Why it is accepted |
|---|---|---|
| `'n'` vs `and` | `Rock 'n' Roll` vs `Rock and Roll` | Trigram overlap cushions it into the low-confidence band rather than the reject band. A rewrite rule is one line if the fixture set shows it matters |
| Digits vs spelled numerals | `2 Princes` vs `Two Princes` | A ten-word bidirectional lexicon creates its own ambiguity (`One`, `Seven` are real titles). Not worth it before evidence |
| Translations and alternate titles | A Spanish-language setlist entry for an English-titled song | Genuinely unsolvable without a metadata source. Prompt 24 (MusicBrainz canonical titles) is where this gets better |
| Typos in crowd-entered titles | `Paranoid Andriod` | Handled *implicitly and well* by the trigram metric — this is precisely what fuzzy matching is for |

---

### 2. Matching algorithm

#### The cascade is three tiers of comparison, not three provider calls

The prompt frames this as *exact → normalized → fuzzy*. That framing is right, but it is worth
stating plainly what it does **not** mean: it is not three escalating searches. It is **one** search
call per song, whose result set is then examined by three progressively more forgiving comparisons,
all in memory.

```
Tier 0  Pre-filter, no provider call
        isTape → Skipped · non-song lexicon → Skipped · medley → split, recurse per segment

Tier 1  Resolution cache lookup (§8)
        hit → done, zero provider calls

Tier 2  ONE StreamingProviderInterface::searchTrack(SongQuery, ProviderTokens)
        → list<TrackCandidate>, typically 20 items

  for each candidate:
    Tier 3  EXACT      comparisonCore(candidate) === comparisonCore(song)
                       AND artistScore == 1.0            → titleScore = 1.0, short-circuit
    Tier 4  NORMALIZED core equality after qualifier extraction, ignoring qualifiers
                                                          → titleScore = 1.0
    Tier 5  FUZZY      weighted trigram Dice + token-set Jaccard (below)

Tier 6  Score every candidate (§3), sort descending, band against thresholds
Tier 7  Persist the resolution — positive or negative — to the cache
```

**The comparison happens against the candidate set returned by one provider search call, not against
a local catalog.** Setlistify holds no track catalog and never will; there is nothing to scan. The
cost of matching one song is therefore *twenty-odd in-memory string comparisons*, not a database
query, not an index scan, and certainly not a cross-join. This is the single fact that makes the
choice of metric a matter of correctness rather than of performance.

#### Evaluating the PHP options

| Option | Verdict | Reasoning |
|---|---|---|
| `levenshtein()` | **Rejected** | **Byte-based, and therefore broken on UTF-8.** `levenshtein('sigur rós', 'sigur ros')` returns **2**, not 1, because `ó` is two bytes — and the denominator `max(strlen(...))` is also in bytes, so accented titles are systematically scored lower than identical unaccented ones. It also silently caps arguments at 255 bytes. This is exactly what `SpotifyTrackMapper::naiveConfidence()` does today, and it is the concrete reason D-83 called that method provisional. Normalization (N1/N1b) removes most accents before comparison, which *masks* the bug rather than fixing it — the residual `æ`, CJK and Cyrillic cases stay broken |
| `similar_text()` | **Rejected** | Documented **O(n³)** worst case, byte-based like the above, and — more damning — **asymmetric**: `similar_text($a, $b)` and `similar_text($b, $a)` can return different values, so candidate ranking would depend on argument order. A ranking function that is not a function of its inputs is not a ranking function |
| PostgreSQL `pg_trgm` | **Rejected — right tool, wrong place** | `pg_trgm` is excellent, and it is designed to find similar rows *in a table*. Our candidates arrive in an HTTP response; using `pg_trgm` would mean `INSERT`ing twenty transient rows per song, then querying them, then discarding them — a round trip per comparison to avoid a computation that takes microseconds. It also drags provider-shaped data through SQL, which is the leak D-73 exists to prevent. **If** a future feature ever matches against a *stored* corpus (prompt 24's MusicBrainz cache is the plausible candidate), `pg_trgm` becomes the right answer for that corpus — and this rejection should be revisited then, not now |
| Character trigram similarity in PHP | **Recommended (primary)** | Robust to typos, insertions and word-order-preserving edits; trivially made code-point-safe with `mb_str_split`; symmetric; linear in string length. Handles `Paranoid Andriod` and `Freeze-Out`/`Freeze Out` without special cases |
| Token-set Jaccard | **Recommended (co-primary)** | Robust to word **reordering** and to extra/missing words, which trigrams handle poorly. Handles `Rosalita` vs `Rosalita Come Out Tonight` far more honestly than trigrams alone, and gives us a natural place to down-weight articles (N6) |

#### The recommended metric

**A fixed blend of both, with exact/normalized short-circuits above it.** Neither alone is adequate:
trigrams over-reward long shared substrings and are blind to token structure; token-set Jaccard is
blind to typos inside a token. Their failure modes are close to orthogonal, which is precisely why
blending them beats either.

```
s_title = 1.0                                       if Tier 3 or Tier 4 fired
        = 0.60 · Dice₃(core_a, core_b)
        + 0.40 · WeightedJaccard(tokens_a, tokens_b)  otherwise
```

Defined precisely:

- **`Dice₃(a, b)`** — build the *set* of 3-code-point windows of each core (whitespace preserved,
  each core padded with one leading and one trailing space so word boundaries participate). Then
  `Dice = 2·|A ∩ B| / (|A| + |B|)`, with `Dice = 1.0` when both are empty and `0.0` when exactly one
  is. **Windows are over code points via `mb_str_split`, never bytes** — this is the explicit fix for
  the `levenshtein()` flaw, and it must be asserted by a test on a diacritic pair.
- **`WeightedJaccard(A, B)`** — tokens are the core split on whitespace. Each token has weight `1.0`,
  except **stop tokens** (`a`, `an`, `the`, `el`, `la`, `los`, `las`, `un`, `una`, `de`, `del`, `y`,
  `and`, `of`, `in`, `on`, `to`, `for`) which have weight `0.25` (N6). Then
  `J = Σw(A ∩ B) / Σw(A ∪ B)` over the token *sets* (duplicates collapsed).
- The 60/40 split favours trigrams because typos and punctuation divergence are more common in
  crowd-entered setlist data than word reordering is. It is a **calibration constant**, lives in
  `profiles.yaml` alongside the weights, and is expected to move once the fixture set has run.

**Fallback order**, in the sense the prompt asks for: exact core equality (Tier 3) → qualifier-aware
core equality (Tier 4) → the blend (Tier 5). If the blend itself ever proves insufficient, the next
escalation is *not* a cleverer metric — it is a second search with a reduced query (title only, no
artist), and D-120 declines to spend that call by default.

#### Cost estimate

Per candidate comparison, on a typical 25–35 code-point title: `mb_str_split` plus ~30 trigrams per
side, two `array_flip`/`array_intersect_key` set operations, and a ≤6-token Jaccard.

| Unit | Estimate |
|---|---|
| One candidate comparison (both metrics) | **~20–50 µs** |
| One song, 20–25 candidates, plus normalization | **~0.7–1.5 ms** |
| One 25-song setlist, all songs, cold cache | **~20–40 ms of CPU** |
| The same setlist's **network** cost (25 sequential provider searches at 150–250 ms) | **~4–6 s** |

Matching is therefore well under **1%** of a generation's wall-clock time. This is the load-bearing
number behind the whole recommendation: there is no performance argument for a cheaper metric, and
no performance argument against a more careful one. The constraint is the provider budget (§7), not
the CPU — so the design should spend CPU freely and calls miserly.

---

### 3. Confidence scoring

#### The formula

```
                    Σ  wᵢ · sᵢ            over signals i that are PRESENT
        raw   =    ────────────────
                    Σ  wᵢ                 same set — weights renormalize over presence

        conf  =    raw                    if s_artist ≥ 0.50
              =    min(raw, 0.45)         if s_artist <  0.50      ← the ARTIST GATE
```

Every `sᵢ ∈ [0,1]`. `conf ∈ [0,1]`. The renormalizing denominator is what makes the formula degrade
gracefully rather than punishing a candidate for metadata the provider simply did not return.

#### The signals

| # | Signal | `w` (Spotify profile) | Present when | Normalization to [0,1] |
|---|---|---|---|---|
| 1 | **Title similarity** | **0.40** | Always | `s_title` from §2 |
| 2 | **Artist match** | **0.25** | Always | Table below |
| 3 | **Version fit** | **0.12** | When at least one candidate in the set carries a Version qualifier, or the candidate does | §4's rule |
| 4 | **Duration proximity** | **0.08** | **Almost never** — see below | `max(0, 1 − |Δ| / 45s)` |
| 5 | **Release-type fit** | **0.06** | When the provider reports an album/release type | `album` 1.0 · `single` 0.85 · `EP` 0.85 · `compilation` 0.45 · `live album` 0.30 (see §4) · unknown → absent |
| 6 | **Artist authority** | **0.05** | When the provider supplies an authority signal (D-119) | `official` 1.0 · `verified` 0.9 · `unknown` 0.4 |
| 7 | **Popularity** | **0.02** | When the provider supplies it | Provider value normalized 0–1 by the adapter |
| 8 | **Provider result rank** | **0.02** | Always | `1 − (rank / count)`, rank 0-based |

Weights sum to **1.00** when everything is present. In practice signal 4 is absent (below), so the
usual denominator is **0.92**.

**Artist sub-score** — `s_artist`, computed by `ArtistSimilarity` over `BandResolver::normalize()`d
values:

| Condition | `s_artist` |
|---|---|
| Candidate's primary artist normalizes equal to the **expected artist** | **1.00** |
| Expected artist appears among the candidate's other credited artists | **0.90** |
| One normalizes to a prefix/superset of the other (`bruce springsteen` ⊂ `bruce springsteen the e street band`) | **0.85** |
| Trigram similarity of the two normalized names ≥ 0.75 | **0.60** |
| Otherwise | **0.00** |

The **expected artist** is `Song::getCoverOfName()` when setlist.fm marked the entry as a cover,
otherwise the performing band's name (D-113).

#### Why duration is defined but almost always absent

**setlist.fm supplies no duration.** There is no duration field on a setlist song, and
`App\Entity\Song` accordingly has no such column — its fields are `position`, `setLabel`, `title`,
`coverOfName`, `coverOfMbid`, `withName`, `info`, `isTape`. `TrackCandidate::$durationMs` carries the
*provider's* duration, but there is nothing on our side to compare it to.

Signal 4 is therefore specified, weighted, and **normally absent** — the denominator drops to 0.92
and the other seven signals absorb its share proportionally. It is defined now rather than later
because (a) prompt 24 (MusicBrainz metadata) is the plausible source that would switch it on, and (b)
writing the formula to renormalize over *presence* from day one is what makes turning a signal on a
config change rather than a re-derivation of every weight.

YouTube uses `durationMs` differently — not as a proximity signal but as a **plausibility filter**
(§6), which is a separate mechanism, not this signal.

#### The artist gate, and why it is a gate rather than a weight

*Right title, wrong artist* is the highest-cost error the system can make. It is also the one a
weighted sum handles badly: a perfect title (0.40), a plausible version fit (0.12), a top rank (0.02)
and a mediocre popularity already carry a wrong-artist candidate to ~0.6 — comfortably inside a
band that would show it to a user, and, in a naïvely-tuned system, near auto-accept. A tribute-band
recording of "Sæglópur" scoring 0.6 is not a small inaccuracy; it is the product failing at its one
job while looking like it worked.

Raising `w₂` instead does not fix this — it makes *every* score artist-dominated, including the ones
where a slight artist mismatch is legitimate (a re-issue credited to a solo name, a
`Band feat. Guest` credit). A **cap** is the right shape: it leaves normal scoring untouched and
places a ceiling below `CHOICE` on candidates whose artist is genuinely unrelated, so they can still
appear in a Normal-mode ranked list (which is honest — the user may know something we do not) but can
never be silently accepted.

`0.45` is chosen to sit just below the reject threshold (0.55), so a gated candidate lands in the
reject band by default.

#### Thresholds, and what they mean per mode

| Band | Range | Fast mode (prompt 14) | Normal mode (prompt 17) |
|---|---|---|---|
| **AUTO_ACCEPT** | `conf ≥ 0.80` | Silently accepted, added to the playlist | Pre-resolved; shown as decided, overridable |
| **CHOICE** | `0.55 ≤ conf < 0.80` | **Accepted and flagged** — added, listed in the report as "we weren't certain about this one" | Presented as a ranked choice with the top 3–5 candidates |
| **REJECT** | `conf < 0.55` | Not added; reported as `not_found` | Not offered; reported as `not_found` |

This three-way banding meaning **different things in each mode** is the design's most consequential
small decision (D-110). Two consequences worth naming:

- Fast mode has no user, so a middle band that "asks" would have to mean "drop". Dropping a 0.7
  match is worse than including it with a flag — the song is probably right, and the report tells the
  truth either way. `CLAUDE.md`'s degradation rule points the same direction.
- Normal mode inherits prompt 15's stated design goal directly: *"only genuinely ambiguous songs need
  a decision, with confident matches pre-resolved"*. On a typical well-covered setlist this band
  contains three to five songs, not twenty-five. The threshold **is** the mechanism that turns 25
  decisions into 3.

**Justification of the numbers.** They are an initial calibration, chosen so that: (a) `0.80` is
reachable only with a strong title *and* a strong artist — with the usual 0.92 denominator, a perfect
artist plus perfect version/type/authority still needs `s_title ≥ 0.63`, so a garbled title cannot
auto-accept on metadata alone; (b) `0.55` is above the artist gate's `0.45` ceiling, so an unrelated
artist is rejected by construction; (c) a candidate with a perfect title and a *related* artist
(`s_artist = 0.60`) lands around `0.72` — the middle band, which is the honest answer. **They are
guesses until §9's harness runs.** Prompt 14 must run the harness and record the tuned values in this
document.

#### Where the thresholds live (D-110)

`backend/config/matching/profiles.yaml`, bound as container parameters, per provider key, with env
overrides for operational emergencies:

```yaml
matching:
  default:
    weights: { title: 0.40, artist: 0.25, version: 0.12, duration: 0.08,
               releaseType: 0.06, authority: 0.05, popularity: 0.02, rank: 0.02 }
    titleBlend: { trigram: 0.60, tokenSet: 0.40 }
    thresholds: { autoAccept: 0.80, choice: 0.55, artistGateFloor: 0.50, artistGateCap: 0.45 }
  profiles:
    <providerKey>: { ... overrides only ... }      ← keys are runtime strings, never literals in PHP
```

**Not in `ProviderSetting` and not in the backoffice.** This is a deliberate departure from the
project's usual instinct, and it deserves the argument rather than the assumption:

- `CLAUDE.md` says the backoffice edits *behaviour*, and a threshold arguably is behaviour. But
  `ProviderSetting`'s flags (`enabled`, `playbackMode`, `isDefault`) share a property a threshold does
  not: **their effect is immediately observable in one request**. Flipping `enabled` off during a
  quota incident has a visible, reversible, instantly-verifiable result. Moving `autoAccept` from
  0.80 to 0.72 changes the quality of every playlist generated from then on, in a way nobody notices
  for weeks.
- The only legitimate way to change a threshold is **against the fixture harness**, with before/after
  numbers. That is a pull request, not a click.
- A threshold change must also bump `algorithmVersion` (§8) so cached resolutions do not silently mix
  two calibrations. A backoffice field cannot enforce that; a config file reviewed in a PR can.

Env overrides (`MATCHING_AUTO_ACCEPT_THRESHOLD`, `MATCHING_CHOICE_THRESHOLD`, and per-provider
suffixed forms) exist as an operational escape hatch only, documented as such in `docs/env-vars.md`.

#### Worked examples

Denominator is 0.92 throughout (duration absent) unless stated.

**Example 1 — auto-accept.** Radiohead, *Everything In Its Right Place*. Setlist entry
`Everything In Its Right Place`; top candidate `Everything in Its Right Place`, Radiohead, album
*Kid A*, album release type, verified artist, popularity 0.70, rank 0.

| Signal | s | w | w·s |
|---|---|---|---|
| Title (Tier 3 exact core match) | 1.00 | 0.40 | 0.400 |
| Artist (exact) | 1.00 | 0.25 | 0.250 |
| Version fit (studio, no qualifiers anywhere) | 1.00 | 0.12 | 0.120 |
| Release type (album) | 1.00 | 0.06 | 0.060 |
| Authority (verified) | 0.90 | 0.05 | 0.045 |
| Popularity | 0.70 | 0.02 | 0.014 |
| Rank | 1.00 | 0.02 | 0.020 |
| | | **Σw = 0.92** | **Σw·s = 0.909** |

`raw = 0.909 / 0.92 = 0.988`. Artist gate not triggered. **conf ≈ 0.99 → AUTO_ACCEPT.**

**Example 2 — auto-accept, via cover attribution.** Pearl Jam, encore, *Rockin' in the Free World*,
which setlist.fm marks as a cover with `coverOfName = "Neil Young"`. The expected artist is therefore
**Neil Young**, not Pearl Jam (D-113). Top candidate: `Rockin' In The Free World`, Neil Young, album
*Freedom*.

| Signal | s | w | w·s |
|---|---|---|---|
| Title (N7 removes the apostrophe on both sides → exact) | 1.00 | 0.40 | 0.400 |
| Artist (exact against the *cover* artist) | 1.00 | 0.25 | 0.250 |
| Version fit (studio) | 1.00 | 0.12 | 0.120 |
| Release type (album) | 1.00 | 0.06 | 0.060 |
| Authority (verified) | 0.90 | 0.05 | 0.045 |
| Popularity | 0.60 | 0.02 | 0.012 |
| Rank | 1.00 | 0.02 | 0.020 |
| | | **0.92** | **0.907** |

**conf ≈ 0.986 → AUTO_ACCEPT**, and the report line reads *"cover of Neil Young"* rather than
presenting it as a Pearl Jam track.

**Example 3 — the middle band.** Bruce Springsteen & The E Street Band; setlist entry `Rosalita`
(the community routinely abbreviates it). Top candidate: `Rosalita (Come Out Tonight)`, credited to
`Bruce Springsteen`, album *The Wild, the Innocent & the E Street Shuffle*.

Title: `(Come Out Tonight)` is a **TitleContinuation** (N4 default), so the candidate core is
`rosalita come out tonight` against a query core of `rosalita`.
`WeightedJaccard = 1 / (1 + 1 + 0.25 + 1 + 1) ≈ 0.235`;
`Dice₃ ≈ 0.46`;
`s_title = 0.60·0.46 + 0.40·0.235 ≈ 0.370`.
Artist: `bruce springsteen` is a prefix of the performing band `bruce springsteen the e street band`
→ `s_artist = 0.85`.

| Signal | s | w | w·s |
|---|---|---|---|
| Title | 0.370 | 0.40 | 0.148 |
| Artist (prefix) | 0.850 | 0.25 | 0.213 |
| Version fit (studio) | 1.000 | 0.12 | 0.120 |
| Release type (album) | 1.000 | 0.06 | 0.060 |
| Authority | 0.900 | 0.05 | 0.045 |
| Popularity | 0.500 | 0.02 | 0.010 |
| Rank | 1.000 | 0.02 | 0.020 |
| | | **0.92** | **0.616** |

`raw = 0.616 / 0.92 = 0.670`. **conf ≈ 0.67 → CHOICE.** Fast mode adds it and flags it; Normal mode
asks. This is the correct behaviour and the correct band: the match is almost certainly right, and
the system is right not to be certain — an abbreviated title is exactly the input where a human
glance is worth more than another 0.05 of algorithm.

**Example 4 — reject, on YouTube's noise.** Sigur Rós, `Sæglópur`. YouTube profile (§6), so the
denominator differs. Candidate #1 is a fan-uploaded phone recording:
`Sigur Ros - Saeglopur (live in Reykjavik 2013) HD`, channel `mattheusk`, duration 11:02.

Normalization: `(live in Reykjavik 2013)` is a **Version** qualifier; ` - HD` and the leading
`Sigur Ros -` remain, so the candidate core is `sigur ros saeglopur hd` against query core
`saeglopur`. `Dice₃ ≈ 0.42`, `WeightedJaccard = 1/4 = 0.25`, `s_title ≈ 0.352`.
Artist: the channel name bears no relation → `s_artist = 0.00`.
Authority: not a Topic or official-artist channel → `unknown` → `0.40`.
Version fit: live, and studio candidates exist in the set → `0.00`.
YouTube profile has no release-type signal; the duration plausibility filter passes (11 min is
inside the band, just).

| Signal | s | w (YouTube) | w·s |
|---|---|---|---|
| Title | 0.352 | 0.40 | 0.141 |
| Artist | 0.000 | 0.25 | 0.000 |
| Version fit | 0.000 | 0.12 | 0.000 |
| Authority | 0.400 | 0.16 | 0.064 |
| Popularity | 0.300 | 0.03 | 0.009 |
| Rank | 1.000 | 0.04 | 0.040 |
| | | **1.00** | **0.254** |

`raw = 0.254`. The artist gate would also cap it at 0.45, but it is already far below.
**conf ≈ 0.25 → REJECT.** Meanwhile the `Sigur Rós - Topic` upload of the studio recording in the
same result set scores ≈ 0.95 and wins — which is precisely the behaviour §6's authority weighting
buys, and the reason YouTube's profile moves 0.11 of weight into `authority`.

---

### 4. Version preference: studio, with a renormalizing fallback

**Recommendation: the studio version is the default.** The argument, since the prompt asks for one
rather than a preference:

**The intuitive case for live is an illusion.** A setlist is the record of *one specific performance*
— the one the user attended. But the live recordings sitting in a provider's catalog are from a
*different* performance: a different tour, usually a different decade, frequently a different lineup,
recorded in a different country in front of a different crowd. Matching a 2023 Barcelona setlist to a
1985 live album does not make the playlist more authentic to that night; it makes it authentic to
somebody else's night. The apparent gain in "liveness" is a real loss in relevance, and it is a loss
the user cannot see or explain.

**Three further effects, each independently sufficient:**

1. **Coverage collapses unevenly.** Live recordings exist in catalogs for a minority of songs, and
   their distribution is lumpy — a band's three most famous songs have live pressings and the other
   fifteen do not. Preferring live would produce playlists that are three live tracks and fifteen
   studio ones, which is worse than either consistent choice.
2. **Live tracks are poor listening artifacts out of context.** Crowd noise, tuning, stage banter,
   and — critically — **segues**: live tracks routinely bleed into the next one, so a shuffled or
   reordered playlist of live cuts sounds broken. Setlist order is meaningful (prompt 13 preserves
   it), but our order is not the live album's order.
3. **Studio versions are the most portable.** They are the best-mastered, most-available, and least
   region-restricted recordings in any catalog, which directly reduces the `region_restricted`
   outcome rate (§5).

**The fallback, which is what makes the default safe.** Some songs exist only as live recordings —
this is common for jam bands, for older material never cut in a studio, and for anything a band
debuted live and never released. Flipping the default for those bands would be the wrong fix. The
right one is a property of the formula: **version fit renormalizes away when no studio candidate
exists.** Concretely:

```
if the candidate set contains ≥ 1 candidate with no Version qualifier:
      s_version = 1.0  for a studio candidate
                = 0.0  for a live candidate
                = 0.5  for acoustic / demo / alternate (a real recording, wrong pressing)
                = 0.3  for remix / instrumental / radio edit
else (every candidate carries a Version qualifier):
      signal 3 is ABSENT — dropped from the numerator and the denominator alike
```

So a live-only song is scored on its title, artist, authority and rank exactly as a studio song
would be, and matches at full confidence. It reaches the playlist, and the report notes *"live
version — no studio recording found"* so the outcome is visible rather than inferred. A remaster is
not a Version penalty in practice, because the `YYYY remaster` qualifier attaches to a candidate
whose core is identical and whose release type is `album` — it competes with the original on
popularity and rank, and either answer is correct.

**Second-order effect worth naming and deliberately not solving.** A tempting refinement is to
*prefer* a live recording whose release year matches the concert's year — a live album from the very
tour the user attended is genuinely the best possible answer. The matcher does have the concert date
available. It is not recommended for the first implementation: it fires for a tiny fraction of songs,
it adds a signal that the fixture set cannot meaningfully evaluate (too few positive cases), and it
is a clean additive change later. Recorded as a future refinement, not as an open question.

**Is it user-configurable? No — not in the MVP (D-112).** Where it would live if it ever is:

- **Not `ProviderSetting`.** That row holds a provider's *behaviour* flags and is global to the
  installation. A listening preference is neither provider-scoped nor operator-owned; putting it
  there would be a category error the backoffice screen would have to explain away.
- **Not env/config.** A per-installation constant is the worst of both worlds: it cannot be
  personalized and it cannot be evaluated.
- **If ever: a `User`-level generation preference**, defaulted to `studio`, surfaced in prompt 17's
  Normal mode. And the argument for waiting is that **Normal mode already is the "let me choose"
  affordance** — a user who wants the live cut of one song can pick it from the ranked list today,
  without a global switch. Shipping the general toggle before the specific mechanism has been used
  would be building for a preference nobody has yet expressed.

---

### 5. Special cases

Every row of this table exists because of `CLAUDE.md`'s rule: **playlist generation degrades, it
does not fail.** Missing setlists, unmatched songs and ambiguous versions are the *normal* case. The
correct posture is therefore never an exception and never a silent drop — it is always *the best
available result, plus an honest report line*. `PlaylistTrack.outcome`
(`docs/architecture.md` §10) is the field that carries it, and **every song in the source setlist
gets a row, including the ones that produced no track**.

| Case | Detection | Reaches the playlist | Reaches the report | Outcome |
|---|---|---|---|---|
| **Cover** | `Song::getCoverOfName()` is non-null | The **original artist's** recording | *"cover of Neil Young"* — named, not hidden | `matched` / `matched_low_confidence` |
| **Medley** | Title contains ` / ` or ` > ` segment separators, or `info` names a medley | One track **per matched segment**, in segment order | One grouped entry with a per-segment outcome list | Per segment |
| **Snippet / tease** | `Song::getInfo()` matches the snippet lexicon; the snippet is **not** a separate setlist entry | Nothing | A contextual note on the parent song (*"with a snippet of …"*) — **never counted as a miss** | n/a |
| **Non-song: tape** | `Song::isTape() === true` | Nothing | *"played over the PA before the set"* | `skipped` |
| **Non-song: performance artifact** | `NonSongClassifier` lexicon, whole-title exact (D-116) | Nothing | *"this was a drum solo"* | `skipped` |
| **Absent from catalog** | Zero candidates, or best `conf < 0.55` | Nothing | *"not available on this provider"* | `not_found` |
| **Only live versions exist** | Every candidate carries a Version qualifier (§4) | The best live recording, at full confidence | *"live version — no studio recording found"* | `matched` |
| **Region-restricted** | The provider rejects the track at insert time (`RegionRestrictedException`, AC-10.1) | Omitted **for this user** | *"not available in your country"* | `region_restricted` |

#### Covers — search by the original artist (D-113)

setlist.fm attributes covers explicitly: `Song::$coverOfName` and `$coverOfMbid` hold the *original*
artist. The question is which name goes into `SongQuery::$bandName`.

**Recommendation: the original artist.** The reasoning is empirical rather than philosophical — in
the overwhelming majority of cases the performing band has **no released recording** of the song they
covered live. Searching for `Sonic Reducer` by *Pearl Jam* returns either nothing or a wrong-artist
candidate that the artist gate then rejects, and we have spent a provider call to learn nothing.
Searching by *Dead Boys* returns the recording that actually exists.

The counter-case is real but rarer: a band that released a studio cover (Johnny Cash's *Hurt*, Jimi
Hendrix's *All Along the Watchtower*). The honest cost of this decision is that we return Nine Inch
Nails' *Hurt* for a Johnny Cash setlist. Two mitigations, in order:

1. The report **names the attribution** (*"cover of Nine Inch Nails"*), so the user sees exactly what
   happened rather than wondering why the track sounds wrong.
2. Normal mode's ranked list is the fix a user can apply in one tap — and prompt 17 should include the
   performing band's own recording among the candidates when the *cover* search yields a
   `CHOICE`-band result, because that is a case where the second search is worth its cost.

Rejected: **two searches per cover** (once by each artist, take the better). It doubles the cost of
the most call-expensive songs in a setlist for a minority improvement, and on YouTube — where one
search is 1% of the daily quota — it is unaffordable (D-120).

#### Medleys — segment, match each, add all

setlist.fm has **no medley field**. The community convention is a single song entry whose `name`
contains the constituent titles separated by ` / ` (occasionally ` > ` for a segue), sometimes with
`info` carrying the word *medley*. *This is a convention, not a schema guarantee* — labelled as an
assumption and verified against the fixture set (§9), not treated as fact.

Behaviour: split on the separators **before** normalization; run the full cascade per segment,
independently; add every matched segment's track in order. A three-song medley therefore costs three
searches and produces up to three playlist tracks.

Data-model consequence for prompt 14: `PlaylistTrack` needs a **segment index** alongside its `Song`
reference, so several rows can hang off one `Song` while preserving order. This is one nullable
smallint on a table prompt 14 is creating anyway, and it is the difference between representing a
medley honestly and flattening it into a single arbitrary pick. The report groups the segments under
the parent entry so the user sees *"Rock and Roll / Whole Lotta Love — both found"* rather than two
unexplained tracks.

A false-positive split is possible (a real title containing a slash, e.g. `Us and Them / Any Colour
You Like`— which is itself usually a genuine medley). The cost is low and self-correcting: the
segments are searched, each either matches or is reported as not found, and nothing crashes. Segment
splitting is only attempted when **every** resulting segment is non-empty and ≥ 2 characters.

#### Snippets and teases — never a miss

setlist.fm records a snippet as free text on the *parent* song's `info` (*"with 'Kashmir' snippet"*,
*"contains a tease of …"*), not as its own entry. So there is nothing to match, and — importantly —
nothing to *fail* to match.

Decision: **snippets are never searched and never counted.** They surface as a contextual note on the
parent song's report line and nowhere else. Attempting to match them would add provider calls for
fragments that have no catalog representation, and would inflate the miss count with entries that
were never songs — corrupting the very metric §9 depends on.

#### Non-song entries — a detection strategy that is not a hardcoded English blocklist (D-116)

Three ordered signals. The first two decide; the third only advises.

**Signal 1 — structural, free, language-independent.** `Song::isTape() === true`. setlist.fm's own
flag, preserved deliberately by prompt 09 (AC-4.3: *"prompt 12 decides what to do with them"* — this
is that decision). It catches the largest class of non-songs, including the walk-on tape, the outro
music and the interlude, in any language, with zero guessing. It should be the first thing the
pre-filter checks.

**Signal 2 — a curated performance-artifact lexicon**, and the prompt is right to be suspicious of it,
so here is the argument for why a curated list is nonetheless the correct tool:

- **The set is genuinely small, closed and slow-moving.** `intro`, `outro`, `interlude`, `encore
  break`, `drum solo`, `bass solo`, `guitar solo`, `keyboard solo`, `jam`, `improv`,
  `improvisation`, `soundcheck`, `banter`, `speech`, `tuning`, plus their Spanish
  (`solo de batería`, `improvisación`), German (`Schlagzeugsolo`), French (`solo de batterie`) and
  Italian forms. This is a list of *performance artifacts*, not a list of song titles — it does not
  grow with music, it grows with languages, and setlist.fm's own community uses a small stable set of
  these labels.
- **It is matched on the WHOLE normalized title, exactly — never as a substring.** This is the
  property that makes it safe. `Intro` by The xx is a real, released song, and so is `Jam` by Michael
  Jackson; a substring match would destroy both. A whole-title exact match against
  `intro`/`jam` still catches those, which is why it must be paired with —
- **— a cheap disambiguator: the entry's position and neighbours.** An entry titled `Intro` at
  position 0 of a set, or `Encore Break` at a set boundary, is an artifact. The same title mid-set,
  for a band whose catalog contains a track of that name, is a song. The classifier consults
  `Song::$position` and `$setLabel`, both already stored.
- **Being wrong is cheap and visible.** A false positive is a song skipped *and named in the report*
  (*"this was a drum solo"*), which the user can see and Normal mode can override. It is not a
  silent drop.
- **The list is data, not code** — `backend/config/matching/non_song_terms.yaml`, versioned, so
  adding a language is a config PR, and so the fixture harness can regression-test it.

The alternative strategies were considered and are worse. *Inferring non-song from zero search
results* fails because zero results is also exactly what a genuinely obscure song looks like — it
would relabel every rare B-side as a drum solo. *A statistical/embedding classifier* is
over-engineering by any reading of this document's own thesis, and would be untestable against a
fixture set of this size.

**Signal 3 — advisory only, never promoting.** A title of ≤ 2 tokens with zero candidates above the
reject threshold is *suspicious*, and the classifier records that, but the outcome remains
**`not_found`, never `skipped`**. We never upgrade a miss into "that wasn't a song" on a heuristic —
that would be the system covering its own failures, which is the precise opposite of the honesty the
product is selling.

**Required precision.** §9 sets the non-song classifier's precision requirement at **1.0**: no real
song may ever be classified as a non-song. Recall can be imperfect (a missed artifact becomes a
`not_found` line, which is mildly noisy but not wrong). This asymmetry is what makes the curated list
defensible.

#### Songs genuinely absent from the catalog

Zero candidates, or a best score below `0.55`. Outcome `not_found`, report line *"not available on
this provider"*, playlist unaffected, job **succeeds**.

Explicitly rejected: lowering the threshold when a song would otherwise be missed. A relaxed
threshold applied selectively to the songs we most want to find is how a matcher learns to lie —
precisely the failure mode the confidence score exists to prevent. The negative result is cached
(§8) with a shorter TTL than a positive one, because catalogs gain songs.

---

### 6. Cross-provider differences

#### The verdict for prompt 18, stated without hedging

**The same algorithm serves both providers. The calibration does not, and prompt 18 needs its own —
as configuration, not as code.**

Concretely, prompt 18 must: (a) implement a `MatchSignals`-populating track mapper inside its own
adapter directory; (b) add a `<providerKey>` profile to `profiles.yaml` with its own weight vector
and thresholds; (c) run §9's fixture harness with YouTube expectations and record the resulting
numbers here. It must **not** need a different `TrackMatcher`, a different `SongNormalizer`, a
different formula, or any change to a file outside `Service/Streaming/<Provider>/` and the two config
files — which is the same claim prompt 10's architecture test already enforces (AC-9.4).

#### Why the catalogs differ, and what that changes

| | Reference adapter (Spotify) | YouTube |
|---|---|---|
| Catalog composition | Curated, label-supplied, one canonical recording per release | Open upload: official releases *and* fan uploads, lyric videos, live phone recordings, full-album uploads, sped-up/slowed edits, covers by anybody |
| Track identity | **ISRC** — an industry-standard recording identifier, present on essentially every track | None. A video id identifies an *upload*, not a recording |
| Artist identity | A first-class artist entity with a stable id, credited on the track | A **channel**, which may be the artist, a label, or a stranger |
| Strongest available signal | **ISRC presence + artist-id equality** — proves the candidate is a properly-licensed recording by the searched artist | **"Topic" channels** — auto-generated channels (`Sigur Rós - Topic`) that carry label-delivered audio, plus official artist channels. An upload on a Topic channel is as close to a canonical recording as YouTube offers |
| Release type | `album` / `single` / `compilation` — a real, reliable signal | Absent |
| Duration | Reliable, matches the recording | Reliable, but of the *upload* — includes 60-minute full-album uploads and 15-second clips |
| Popularity | A normalized track popularity figure | View count, which correlates with virality more than with canonicity |

#### The seam

**Provider-agnostic** (`App\Service\Matching\`, contains no provider symbol and no provider key
literal):

- `SongNormalizer` and its lexicons — a title normalizes identically regardless of where it is going.
- `TitleSimilarity`, `ArtistSimilarity` — pure string functions.
- `MatchConfidence` — the formula, the renormalization, the artist gate.
- `NonSongClassifier` — a drum solo is a drum solo on every provider.
- `TrackMatcher` — the cascade and the banding.
- `TrackResolutionStore` — keyed *by* provider, but knowing nothing *about* any provider.

**Behind `StreamingProviderInterface`, inside each adapter directory** (D-82's ban applies in full):

- **Query construction** — how a `SongQuery` becomes the provider's search string, including any
  field syntax, market/region parameter, or result-type filter. The reference adapter's
  `market` parameter and YouTube's `type=video`/`videoCategoryId` live here and nowhere else.
- **Response mapping** — turning the provider's payload into `TrackCandidate`, which the reference
  adapter's `SpotifyTrackMapper` already does. It **loses** `naiveConfidence()` entirely (redeeming
  D-83) and **gains** the job of populating the generic signal fields.
- **Signal extraction** — deciding what fills `artistAuthority`, `albumType`, `popularity` and
  `isrc`. This is where "is this a Topic channel?" and "does the credited artist id equal the
  searched artist id?" are asked. Those questions are provider knowledge; their *answers* are
  provider-agnostic values.
- **Error mapping** — already the adapter's job (D-73), unchanged.

The generic fields are the seam's whole trick (**D-119**). `TrackCandidate` gains, as
provider-agnostic additions:

| Field | Type | Reference adapter fills it from | YouTube fills it from |
|---|---|---|---|
| `artistAuthority` | enum `Official` / `Verified` / `Unknown` | Artist id equality against the searched artist → `Verified`; a first-party label release → `Official` | A `… - Topic` channel or an official artist channel → `Official`; a label channel → `Verified`; anything else → `Unknown` |
| `albumType` | enum, nullable | The release's album type | `null` — absent, and the weight renormalizes away |
| `popularity` | `?float` 0–1 | The provider's popularity, scaled | View-count percentile **within the candidate set** |
| `isrc` | `?string` | The track's ISRC | `null` |
| `providerRank` | `int` | Position in the response | Position in the response |

Note that `isrc` is an industry standard (ISO 3901), not a provider symbol, so naming it in a shared
model is safe under D-82's rule — which is about PHP symbols belonging to a *provider*. It is not
consumed by the formula in this design; it is carried because it is the strongest possible
cross-provider bridge and will be the natural key if a future feature ever resolves a track on one
provider from a resolution on another. Recorded, not built.

#### YouTube's two extra mechanisms

Both are **provider-agnostic in shape, provider-tuned in configuration** — neither requires new code
outside the profile file except the plausibility band, which is one entry in `profiles.yaml`.

1. **Weight redistribution.** With `albumType` absent and `artistAuthority` unusually informative,
   YouTube's profile moves weight into authority and rank:
   `{ title: 0.40, artist: 0.25, version: 0.12, authority: 0.16, popularity: 0.03, rank: 0.04 }`
   — summing to 1.00 with no duration and no release-type signal. A Topic-channel upload thus starts
   0.096 ahead of an identical fan upload before any other signal is considered, which is exactly the
   discrimination the catalog requires (see §3's worked example 4).
2. **A duration plausibility filter, not a proximity signal.** Since setlist.fm gives no duration,
   there is nothing to be proximate *to* — but YouTube's noise has a characteristic duration
   signature. Candidates outside a configured band (`90s ≤ duration ≤ 12min` by default) are
   **excluded from the candidate set before scoring**, which removes full-album uploads (40–70 min)
   and clip fragments (< 90 s) at zero scoring cost. The band is per-provider config, disabled by
   default, enabled for YouTube. Songs legitimately outside it exist (a 20-minute prog epic), which
   is why the band is generous and why exclusion is logged into the match result's reason codes.

#### Initial YouTube thresholds — a starting number, not a guess to leave in place

`autoAccept: 0.85` (raised from 0.80), `choice: 0.60` (raised from 0.55). **Raising** the auto-accept
bar is deliberate and worth stating, because the instinct runs the other way: YouTube's noisier
catalog means more candidates *look* plausible, so a wrong silent pick is likelier, and the cost of
one is higher — a fan-recorded phone video sitting in a user's playlist is a more visible failure
than a missing track. The consequence is more songs in the `CHOICE` band, which in Fast mode means
more flagged-but-included tracks and a longer report. That is the right trade: on YouTube the product
should be *more* visibly uncertain, not less.

These numbers must be replaced by harness output in prompt 18, per §9.

---

### 7. Budget and performance

#### setlist.fm — matching spends none of it

Worth stating first because it is a genuinely good property and easy to assume otherwise.
`App\Service\Matching\` holds **no reference to `App\Service\Setlist\SetlistGateway`** and needs
none. By the time matching runs, the setlist has already been fetched, normalized and persisted as
`Setlist` + `Song` rows (D-60). Matching reads Doctrine entities. **Zero of the 1,440 daily requests
are consumed by matching, at any volume**, and the static test that keeps `SetlistGateway` the only
door (D-58, AC-6.5) will keep it that way without anyone remembering to.

The setlist.fm budget is spent *upstream* of matching — selecting the setlist — and prompt 13 owns
that. Nothing in this design changes the arithmetic prompt 09 already established.

#### Provider calls for a 25-song setlist

Under the recommended strategy — **one search per unresolved song, no speculative second search**
(D-120):

| Scenario | Searches | Notes |
|---|---|---|
| Cold cache, 25 songs, no medleys, no non-songs | **25** | The baseline |
| Realistic 25-entry setlist: 2 tape/non-song entries pre-filtered, 1 three-segment medley | **25** | −2 for the pre-filter, +2 for the medley segments. The pre-filter is free budget |
| Second user, same band, same setlist, warm cache | **0** | Every resolution is user-independent (§8) |
| Second user, same band, *different* setlist (heavy overlap) | **3–8** | Bands repeat their setlists heavily tour to tour; this is the common case and the cache's real payoff |

**Batching is not available on either provider.** Both search endpoints accept exactly one query per
call — there is no multi-query form, and constructing an `OR`-style query returns a merged,
unattributable result set that destroys the per-song ranking the whole design depends on. This is a
factual limit, not a design choice: the only lever available is *not making the call*, which is why
§8 is load-bearing rather than an optimization.

#### The reference adapter (Spotify)

Rate-limited on a rolling window whose exact figure Spotify does not publish; empirically generous
for this volume. 25 sequential searches at 150–250 ms each is **4–6 seconds** of wall time — already
far past a sane HTTP timeout, which is why prompt 13 makes generation asynchronous. Recommendation:
**issue searches sequentially**, not concurrently. Concurrency buys a few seconds on a job that is
already async, and costs the ability to stop cleanly the moment a rate limit or a quota gate fires.
The 5-user Development Mode cap means real-world Spotify volume is bounded at five people anyway.

#### YouTube — the arithmetic that decides the design

`docs/external-apis.md` §YouTube: **10,000 units/day**, a search costs **100**, a playlist insert
costs **50**.

```
Searches only:      10,000 / 100                          =   100 searches/day
                    100 / 25 songs                        =     4 playlists/day     ← the prompt's figure

Counting inserts, one full 25-song generation:
        25 searches        × 100 =  2,500 units
         1 playlist create ×  50 =     50 units
        25 track inserts   ×  50 =  1,250 units
                            total =  3,800 units          =    38% of the entire day

Real capacity:      10,000 / 3,800                        =   2.6 generations/day
```

**Two-point-six playlists per day, for the entire application, on a cold cache.** Not per user — the
quota is a single application-wide budget, exactly like setlist.fm's 1,440. That number is not a
performance concern; it is the product's viability, and it drives three design consequences:

1. **The cache is not an optimization — it is the only thing that makes YouTube viable.** A warm
   resolution costs zero units. If the average generation resolves 80% of its songs from cache, the
   per-generation cost drops to ~1,850 units and capacity rises to ~5.4/day; at 95% it is ~1,475
   units and ~6.8/day. The cache moves capacity by a factor, and nothing else available does.
   §8 is therefore the most consequential section of this document for YouTube specifically.
2. **A generation must be gated upfront, not discovered halfway.** Prompt 18 must check remaining
   quota against the *estimated* cost of the whole generation (`songs × 100 + songs × 50 + 50`,
   discounted by known cache hits) **before starting**, and refuse cleanly with a
   `QuotaExhaustedException`-shaped outcome rather than producing a half-filled playlist and an empty
   quota. Prompt 13 owns which of "refuse upfront" or "stop cleanly partway" the pipeline does; this
   section is the input to that decision, and its recommendation is **refuse upfront**, because a
   playlist that stops at song 11 has consumed the whole day's quota to produce something the user
   did not want.
3. **`ProviderSetting.enabled` earns its keep.** `docs/external-apis.md` already names quota
   exhaustion as the primary reason the runtime kill switch exists. Matching is the thing that
   exhausts it.

Requesting a quota increase from Google is an operational action, costs nothing to ask, and — like
setlist.fm's higher rate tier (D-69) — changes a configured number rather than any code. Prompt 18
should do it the day the integration works.

#### Matching's own cost

From §2: **~1 ms of CPU per song, ~20–40 ms per 25-song setlist** — under 1% of the generation's
wall-clock time, which is dominated by 4–6 seconds of sequential HTTP. Normalization is a handful of
`preg_replace` calls on short strings. There is no scenario in which matching CPU is the bottleneck,
and no reason to trade accuracy for speed anywhere in this design.

---

### 8. Caching

#### What is cacheable, and what is emphatically not

The critical separation, and the reason this section is not simply "cache the search response":

| Layer | Reusable across users? | Why |
|---|---|---|
| **Song → track resolution** — *"the setlist entry `Sæglópur` by Sigur Rós resolves to track X with confidence 0.99"* | **Yes, fully** | It is a fact about two catalogs, not about a person. It does not depend on who asked, where they are, or what they have linked |
| **Track availability** — *"track X is playable for this user"* | **No** | Region restrictions are per-market; a track available in Spain may be blocked in Japan. It is also mutable in a way resolution is not — licences lapse, YouTube videos are deleted |

Conflating these is the mistake this design most wants to avoid: caching availability globally
produces playlists that silently omit tracks for users in the wrong country, and *not* caching
resolution throws away the only lever that makes YouTube viable (§7).

**Availability is never cached in the shared layer.** It is discovered at insert time, when the
adapter raises `RegionRestrictedException` (AC-10.1), producing a per-user `region_restricted`
outcome. If prompt 18 finds that per-user availability checks are themselves expensive, the answer is
a **short-TTL, per-market** Redis key (`avail:<provider>:<trackId>:<market>`, 24 h) — an
optimization scoped to one adapter's needs, not a shared concept, and explicitly not part of the
resolution cache.

#### Store: a Doctrine-persisted table with a Redis read-through (D-121)

**Recommendation: PostgreSQL is the source of truth; Redis is the volatile tier in front of it.**
The same two-tier shape as `SetlistCacheEntry` (D-59/D-60), for the same reasons plus one:

- **A resolution is expensive to produce and cheap to keep.** One YouTube resolution cost 1% of the
  application's daily quota. Redis is an eviction-policy-governed cache — under memory pressure it
  discards whatever it likes, and what it would be discarding here is *budget already spent*. That is
  the wrong risk to accept for a few kilobytes a row.
- **Resolutions are queryable data, not telemetry.** The evaluation harness (§9) reads them, the
  backoffice will want to list them (*"why did this song resolve to that track?"*), and prompt 14's
  regression test compares them across algorithm versions. None of that is possible over an opaque
  Redis keyspace. This is the same argument D-60 made for storing setlists relationally as well as
  verbatim.
- **Redis is still the right front tier.** A generation resolves 25 songs in a burst, and a
  round-trip per song to PostgreSQL is unnecessary when the same rows are hot. Redis absorbs repeats
  within and across nearby generations; a durable-tier hit **promotes** into Redis exactly as
  `SetlistCache` already does (AC-6.2).

Rejected: **Redis only** — loses spent budget on eviction, unqueryable, and would make the §9 harness
depend on a cache being warm. Rejected: **PostgreSQL only** — correct but needlessly chatty for a
burst workload, when the promotion pattern already exists in this codebase.

#### The entity

```
TrackResolution
  id                uuid
  provider          string        ← StreamingProviderInterface::key(), a runtime string
  algorithmVersion  smallint      ← bumped by any normalizer / formula / threshold change
  normalizedTitle   string(200)   ← SongNormalizer output: comparisonCore
  normalizedArtist  string(200)   ← BandResolver::normalize() of the EXPECTED artist (D-113)
  providerTrackId   string null   ← NULL = a cached negative result
  confidence        real          ← the winning candidate's score
  outcome           string        ← matched | matched_low_confidence | not_found
  candidatesDigest  jsonb         ← the top 5 candidates + their sub-scores, for §9 and the backoffice
  resolvedAt        timestamptz
  expiresAt         timestamptz   ← see TTLs below
  UNIQUE (provider, algorithmVersion, normalizedArtist, normalizedTitle)
```

#### The cache key

`provider | algorithmVersion | normalizedArtist | normalizedTitle`

Each component earns its place, and one candidate component is deliberately **excluded**:

- **`provider`** — obviously. A track id is provider-scoped.
- **`algorithmVersion`** — the invalidation mechanism (below). Without it, a threshold change silently
  mixes two calibrations in one dataset, and §9's harness cannot tell them apart.
- **`normalizedArtist`** — the *expected* artist, so a cover is keyed by the original artist and
  benefits every band that covers the same song. This is a real hit-rate gain: covers cluster.
- **`normalizedTitle`** — the `comparisonCore`, so `Rosalita` and `rosalita` and `Rosalita ` are one
  entry, and — importantly — so are two crowd-entered spellings that normalize identically. The cache
  key is thus *doing normalization's job a second time*, which is a feature: two users whose setlists
  spell a title differently share one resolution.
- **`market` / region — deliberately NOT in the key.** This is the design's clearest expression of
  the resolution/availability split. Which recording *is* "Sæglópur by Sigur Rós" does not depend on
  where the asker is standing; whether they may *play* it does. Including market in the key would
  fragment the cache by the number of countries the userbase spans — the exact opposite of what §7
  needs — while solving a problem that belongs to the availability layer.

**How a cached resolution interacts with a user in a different region.** It is used, unchanged. The
resolution names a recording; the insert attempt then either succeeds or raises
`RegionRestrictedException`, producing a `region_restricted` outcome for *that user's* playlist and
that user's report line only. The cached row is not invalidated — it is still the correct resolution
for everyone else, and marking it bad because of one user's geography would be a cache poisoning bug
that is very hard to notice. A track that is region-restricted for *every* user will simply produce
that outcome every time, which is visible in the report and, later, in the backoffice.

#### TTLs and invalidation

| Entry | TTL | Reasoning |
|---|---|---|
| Positive resolution (`matched`) | **180 days** | Catalogs are stable; a licensed recording rarely stops being the right answer. Long TTL is the point |
| Positive but uncertain (`matched_low_confidence`) | **60 days** | Shorter, so a better answer that appears in the catalog is found sooner. These are the ones most likely to be improvable |
| Negative (`not_found`) | **30 days** | Catalogs *gain* songs — a shorter TTL is the mechanism by which a newly-added track is eventually found. Long enough that a genuinely absent song is not re-searched every generation |
| Redis front tier | **300 s**, matching `SETLISTFM_CACHE_TTL`'s posture | Its only job is absorbing repeats within a burst |

**Invalidation triggers**, in order of importance:

1. **`algorithmVersion` bump** — any change to `SongNormalizer`, `TitleSimilarity`,
   `MatchConfidence`, the weight vectors, the thresholds, or the non-song lexicon. New rows are
   written under the new version; **old rows are kept**, which is what lets §9's harness diff two
   calibrations over the same corpus. A background prune removes versions older than the previous
   one.
2. **`NotFoundException` from the provider at insert time** — the track id no longer exists (YouTube
   videos are deleted routinely; catalog items are withdrawn). Delete the row and re-resolve on the
   next generation. This is the one runtime invalidation that must exist.
3. **`expiresAt`** — the passive backstop above.
4. **An operator action** in the backoffice, audited via `AuditLogger` — *"forget this resolution"* —
   for the day a support conversation identifies a specifically wrong match. Nice to have, not
   required in prompt 14; noted so prompt 14 does not build a schema that precludes it.

---

### 9. Evaluation method

#### Why this section is not optional

Prompt 14's own risk note is exact: the fixture-based quality test *"is the only thing standing
between a matching tweak and a silent regression across every future generation"*. Every threshold in
§3 is a guess until this harness has run. The harness is not a test of the code; it is the instrument
that turns "this feels better" into a number.

#### The fixture set

Eight real, hand-labelled setlists, chosen so that every hard case in §5 appears at least twice, and
chosen from bands with dense setlist.fm coverage so the fixtures are capturable. Each fixture is a
committed setlist.fm response payload (already the project's practice — D-70, AC-13.4) plus a
committed provider search response per song (D-85's practice) plus a **hand-labelled expected track
id** per song, or an explicit `expected: not_found` / `expected: skipped`.

| # | Setlist | Chosen for | Hard cases it contributes |
|---|---|---|---|
| 1 | **Radiohead** — Madison Square Garden, New York, 2018 | The clean baseline: dense catalog, canonical titles | The control group. If this one is not near-perfect, something is broken |
| 2 | **Bruce Springsteen & The E Street Band** — Estadi Olímpic, Barcelona, 2023 | **Abbreviations** and long parenthetical titles | `Rosalita` → `Rosalita (Come Out Tonight)`; `Tenth Avenue Freeze-Out`; billed-artist vs credited-artist mismatch (`s_artist = 0.85` path) |
| 3 | **Pearl Jam** — 2022 tour | **Covers**, and improvisation entries | `Rockin' in the Free World` (Neil Young), `Sonic Reducer` (Dead Boys); an `Improv` entry for the non-song classifier |
| 4 | **Metallica** — M72 tour, Johan Cruijff ArenA, Amsterdam, 2023 | **Tape entries** and live-vs-studio pressure | `The Ecstasy of Gold` as `isTape = true`; songs with prominent live-album pressings competing with the studio original |
| 5 | **Sigur Rós** — 2022 tour | **Non-Latin / diacritic titles** and the N4 catastrophe case | `Sæglópur` (N1b ligature fold), `Hoppípolla`, `Untitled #1 (Vaka)` / `Untitled #3 (Samskeyti)` — the parenthetical-extraction test that blind stripping fails |
| 6 | **Vetusta Morla** — WiZink Center, Madrid, 2023 | **Spanish diacritics and leading articles** | `Los Días Raros`, `Copenhague`; verifies N6 keeps the article and N1 folds the accent |
| 7 | **Phish** — any 2023 show | **Medleys, segues and jams** | ` > ` segue notation, multi-segment entries, `Jam` entries that must be `skipped`; also verifies the medley-convention assumption |
| 8 | **A small support act** on any of the above bills | **Absent from catalog** | Songs with genuinely no provider presence — the `not_found` control. Also the case where a same-titled song by an unrelated artist tempts a false positive (the artist gate's test) |

Between them: **≥ 6 covers, ≥ 4 medleys, ≥ 8 non-song entries, ≥ 12 diacritic/non-Latin titles,
≥ 5 abbreviations, ≥ 6 songs absent from the catalog**, and roughly 180–220 labelled song entries in
total. That is small enough to hand-label in a day and large enough for the precision figures below
to mean something at two significant figures.

**Labelling protocol.** One human, one pass, recording for each entry: the expected outcome, the
expected provider track id where applicable, and a free-text reason. Ambiguous entries (*is this
`Jam` a song or an artifact?*) are labelled with the honest answer and a note, because those are
exactly the entries that will be argued about later.

#### The metric, and which one matters most

Four numbers, computed per provider over the whole fixture set.

| Metric | Definition | Spotify target | YouTube target |
|---|---|---|---|
| **Auto-accept precision** ★ | correct / auto-accepted (`conf ≥ AUTO`) | **≥ 0.95** | **≥ 0.90** |
| **Coverage** | (correct auto-accepted + correct low-confidence) / matchable songs | ≥ 0.85 | ≥ 0.70 |
| **Silent-error rate** | wrong auto-accepts / all matchable songs | **≤ 0.03** | ≤ 0.05 |
| **Non-song precision** | non-song classifications that were genuinely non-songs | **= 1.00** (hard) | = 1.00 (hard) |

*"Matchable"* excludes correctly-skipped non-songs; a drum solo is neither a hit nor a miss.

★ **Auto-accept precision is the metric that matters most, and it is worth saying why explicitly.**
Fast mode's auto-accept band is the only place the system acts *without telling the user it made a
judgement call*. Every other outcome is visible: a low-confidence match is flagged, a miss is named
in the report, a skip is explained. An error in those bands is a nuisance the user can see and
correct. An error in the auto-accept band is a lie — a track the user did not choose, presented as
though it were obviously right.

**A wrong silent pick is worse than an honest miss**, and not by a small margin: the miss costs one
track and buys trust (*"it told me it couldn't find that one"*), while the wrong pick costs one track
and spends trust across the entire playlist (*"if that one's wrong, which of the other 24 are?"*).
That asymmetry is the reason the thresholds sit where they do, the reason the artist gate is a cap
rather than a weight, and the reason coverage is a secondary target that may be traded down to
protect precision — but never the reverse.

Non-song precision is a **hard** requirement rather than a target: a real song classified as a drum
solo is a silent error of the worst kind, disguised as a deliberate skip. If the lexicon cannot hit
1.00 on this corpus, the lexicon is wrong and must shrink.

#### The regression gate

The harness ships as a test in the default suite (`@group matching-quality`), running entirely on
committed fixtures — **zero outbound calls**, per D-2/D-70/D-85. It:

1. Runs the full cascade over every fixture entry against recorded provider responses.
2. Computes the four metrics per provider.
3. **Fails the build** if auto-accept precision or non-song precision falls below target, or if the
   silent-error rate rises above it.
4. Writes a machine-readable report (`var/matching-report.json`) with per-song outcomes, so a diff
   between two runs shows *which* songs changed, not just that the number moved.

#### How a future change proves itself an improvement

The rule, stated so it can be quoted in a code review:

> A matching change is an improvement if, on the frozen fixture set, it **does not decrease
> auto-accept precision** and **increases coverage** — or decreases the silent-error rate at equal
> coverage. Any other combination is a trade, and a trade must be argued in the pull request with
> both numbers, not asserted.

Mechanically: bump `algorithmVersion`, run the harness against both versions over the same corpus
(the cache keeps both, by design — §8), attach the diff. The fixture set is **frozen**: adding
fixtures is allowed and encouraged, but a change may not add fixtures and change the algorithm in the
same pull request, because that makes the before/after incomparable. New fixtures land first, with
the current numbers recorded; the change lands second.

---

## Decisions

Numbered from **D-106**. `D-1`–`D-3` are project-wide (`docs/architecture.md`), `D-4`–`D-9` backend
skeleton, `D-10`–`D-17` frontend skeleton, `D-18`–`D-23` auth, `D-24`–`D-31` concert domain,
`D-32`–`D-41` concert tracker UI, `D-42`–`D-55` backoffice foundation, `D-56`–`D-70` setlist.fm,
`D-71`–`D-88` streaming port, `D-89`–`D-105` provider configuration.

**D-106 — Song normalization is its own pipeline; only the artist side reuses
`BandResolver::normalize()`.**
The two problems look identical and are not. `normalizedName` sits behind a UNIQUE index on
`bands.normalized_name`, so its function is effectively frozen — a change is a data migration. Song
normalization will be tuned repeatedly against the fixture set, must return a structure rather than a
string, and must **keep** leading articles (`The End` ≠ `End`), which band normalization strips. So:
no shared code for titles, deliberate reuse for artists, and a shared *shape* (pure static function,
NFKD, no database involvement) so neither drifts into being hard to re-derive. Cost accepted: two
normalizers to keep mentally straight, and a reviewer who has to be told why. Cheaper than coupling a
matching tweak to a band-dedup migration.

**D-107 — Normalize for comparison; send the raw title to the provider.**
The obvious move — normalize, then search for the normalized string — is wrong. Provider search
engines are better at diacritics, stopwords and punctuation than a regex pipeline is, and stripping
before the query discards recall that no later scoring can recover. Normalization runs on both sides
*after* the response, identically. Consequence: `SongQuery::$songTitle` keeps carrying the raw title,
so its shape is unchanged and no adapter is affected.

**D-108 — Parentheticals are extracted and classified, never blindly stripped.**
`Untitled #1 (Vaka)` blind-stripped becomes `untitled 1`, equidistant from `Untitled #2`, `#3`, `#4` —
a catastrophic false match on a real band with real songs. Blind *keeping* makes the studio and live
pressings equally distant from the query, which makes §4 inexpressible. So every parenthetical is
classified as Version / FeaturedCredit / **TitleContinuation**, with continuation as the **default**:
an unrecognized parenthetical is far likelier to be part of the title than a version marker we failed
to enumerate, and keeping it is the recoverable error.

**D-109 — Confidence is a weighted sum renormalized over *present* signals, with a hard artist gate.**
Missing metadata is the normal case, not an anomaly — setlist.fm supplies no duration at all, and
YouTube supplies no release type. Scoring an absent signal as 0 would punish candidates for their
provider's silence; scoring it as 0.5 would invent evidence. Renormalizing the denominator over
present signals is the only honest option, and it makes turning a future signal on (prompt 24's
MusicBrainz durations) a config change rather than a re-derivation of every weight. The artist gate
is a **cap, not a weight**, because *right title, wrong artist* is the highest-cost error class and a
weighted sum handles it badly — raising the artist weight would make every score artist-dominated,
including the legitimate near-misses.

**D-110 — Three outcome bands, meaning different things per mode; thresholds are deploy-gated
configuration, not a backoffice field.**
`AUTO ≥ 0.80` / `CHOICE ≥ 0.55` / `REJECT`. Fast mode has no user, so its middle band means
*included and flagged* rather than *dropped* — a 0.7 match is probably right and the report tells the
truth either way. Normal mode's middle band is the ranked choice, which is the mechanism that
delivers prompt 15's stated goal of turning 25 decisions into three. On location: unlike
`ProviderSetting`'s flags, whose effect is immediately observable and instantly reversible, a
threshold change silently alters the quality of every playlist generated afterwards and is
unobservable for weeks. The only legitimate way to change one is against the fixture harness with
before/after numbers — a pull request, not a click — and a change must bump `algorithmVersion`, which
a config file reviewed in a PR can enforce and a form field cannot. Env overrides exist as an
operational escape hatch, documented as such.

**D-111 — Studio is the default version; version fit renormalizes away when no studio candidate
exists.**
The catalog's live recordings are from a *different* performance than the one the user attended —
usually a different tour, decade and lineup — so the intuitive authenticity of preferring live is an
illusion, while its costs (uneven coverage, segued tracks, crowd noise, more region restrictions) are
real. The fallback is what makes the default safe: when every candidate carries a Version qualifier,
signal 3 is dropped from numerator and denominator alike, so a live-only song matches at full
confidence and is reported as *"live version — no studio recording found"*. Flipping the default for
jam bands is rejected; the renormalization already covers the case that motivates it.

**D-112 — Version preference is not user-configurable in the MVP; if it ever is, it belongs on
`User`, not `ProviderSetting`.**
Normal mode **already is** the "let me choose" affordance — a user who wants the live cut of one song
picks it from the ranked list today. Shipping a global toggle before that specific mechanism has been
used in anger is building the general case before the specific one is proven. And if it is ever
wanted, it is a per-person listening preference, not a provider behaviour flag: `ProviderSetting` is
installation-global and operator-owned, and putting a taste preference there is a category error the
backoffice screen would have to apologize for.

**D-113 — A cover is searched by the *original* artist.**
setlist.fm attributes covers explicitly (`Song::$coverOfName`), and in the overwhelming majority of
cases the performing band has no released recording of what they covered — so searching by the
performing band spends a provider call to learn nothing. The honest cost: a band that *did* release a
studio cover (Johnny Cash's *Hurt*) returns the original artist's version. Mitigated by naming the
attribution in the report and by Normal mode's one-tap override. Rejected: two searches per cover —
it doubles the cost of the most call-expensive entries in a setlist, and at 100 YouTube units per
search that is unaffordable (D-120).

**D-114 — A medley is segmented, matched per segment, and every matched segment reaches the playlist.**
setlist.fm has no medley field; the community convention is one entry with ` / ` (or ` > `)
separators, which this spec treats as an **assumption to verify against the fixture set**, not a
fact. Flattening a medley into one arbitrary pick loses two-thirds of the music the band actually
played. The data-model consequence is one nullable segment index on `PlaylistTrack` — a table prompt
14 is creating anyway — so several rows can hang off one `Song` in order. A false split is cheap and
self-correcting: each segment is searched and either matches or is reported.

**D-115 — Snippets and teases are never searched and never counted as misses.**
They live in the parent song's `info` text, not as separate entries, and have no catalog
representation. Searching them would spend calls on fragments and — worse — inflate the miss count
with things that were never songs, corrupting the metric §9 depends on. They surface as a contextual
note on the parent's report line and nowhere else.

**D-116 — Non-song detection is three ordered signals: `isTape`, then a curated whole-title lexicon,
then an advisory-only heuristic that never promotes a miss into a skip.**
`Song::$isTape` is setlist.fm's own flag — free, structural, language-independent, and preserved by
prompt 09 precisely so this decision could be made here (AC-4.3). The curated lexicon is defensible
despite the instinct against blocklists because the set is genuinely small, closed and slow-moving
(performance artifacts, not song titles), because it is matched on the **whole normalized title
exactly** — never a substring, so `Intro` by The xx and `Jam` by Michael Jackson survive — because it
is disambiguated by `position`/`setLabel`, because it is **data rather than code** so adding a
language is a config PR, and because being wrong is cheap and visible (a skip is named in the
report). The rejected alternative — inferring non-song from zero search results — fails because zero
results is also exactly what a rare B-side looks like. The third signal is advisory only: a
suspicious short title with no candidates stays `not_found`, never `skipped`, because upgrading a
miss into "that wasn't a song" is the system covering its own failures. §9 sets this classifier's
required precision at **1.00**.

**D-117 — A song below the reject threshold is `not_found`; the threshold is never relaxed to find it.**
Selectively lowering the bar for songs we most want to match is how a matcher learns to lie — the
precise failure mode the confidence score exists to prevent. The negative result is cached with a
shorter TTL than a positive one (30 days), because catalogs gain songs and that TTL is the mechanism
by which a newly-added track is eventually found.

**D-118 — One algorithm, per-provider calibration. Prompt 18 needs its own numbers, as configuration,
not as code.**
Stated without hedging because prompt 18 depends on the answer. The normalizer, the metrics, the
formula, the gate, the classifier and the cache are provider-agnostic and live in
`App\Service\Matching\`, which contains no provider symbol and no provider key literal. Query
construction, response mapping, signal extraction and error mapping live inside each adapter
directory, where D-82's ban already puts them. What differs per provider is a **weight vector and a
threshold set in `profiles.yaml`**, keyed by `StreamingProviderInterface::key()` — a runtime string,
so no provider name enters PHP source. YouTube's profile moves 0.11 of weight into `artistAuthority`
(the catalog's strongest available signal) and **raises** its auto-accept bar to 0.85, because a
noisier catalog makes a wrong silent pick both likelier and more visible.

**D-119 — `TrackCandidate` gains provider-agnostic signal fields; this does not reopen D-71.**
D-71 freezes the *port* at nine methods. `TrackCandidate` is a shared value object outside every
adapter directory, and AC-9.2's requirement is that it contain no *provider-shaped* fields — which
`artistAuthority`, `albumType`, `popularity`, `isrc` and `providerRank` satisfy: each is a generic
concept every provider can either answer or leave null, and a null renormalizes away by D-109. This
is the seam that lets "is this a `… - Topic` channel?" be asked inside one adapter while the scorer
sees only `artistAuthority: Official`. `isrc` is an ISO standard rather than a provider symbol; it is
carried but not consumed, because it is the natural key for any future cross-provider resolution
bridge.

**D-120 — One search per song. No speculative second search, ever.**
Neither provider's search endpoint supports batching — one query per call, and an `OR`-style merged
query destroys the per-song attribution the ranking depends on. So the only lever is *not making the
call*, which is D-121's job. A second search (title-only, or by the performing band for a cover) is
explicitly declined as a default: on YouTube it doubles the cost of the most expensive entries
against a budget that already permits only ~2.6 generations a day. Prompt 17 may spend a second
search **on an individual song a user is actively looking at**, which is a different economics
entirely — one call, one human, on demand.

**D-121 — Resolutions are cached in a Doctrine table with a Redis read-through; availability is not
cached at all.**
The same two-tier shape as `SetlistCacheEntry` (D-59/D-60) and for the same reasons plus one: a
resolution is *budget already spent* — one YouTube resolution cost 1% of the application's day — and
Redis is an eviction-policy-governed cache that discards whatever it likes under memory pressure.
That is the wrong risk for a few kilobytes a row. Resolutions are also queryable data the §9 harness
and the backoffice both need, which an opaque keyspace cannot serve. Redis stays the front tier with
promotion on a durable hit, exactly as `SetlistCache` already does. Availability is deliberately
excluded from the shared layer: it is per-market and mutable, and caching it globally would silently
omit tracks for users in the wrong country.

**D-122 — The evaluation harness is a build-failing regression gate, and the fixture set is frozen
across a change.**
Every number in §3 is a guess until the harness runs, so prompt 14's first obligation is to run it
and record the tuned values in this document. The gate fails the build on a drop in auto-accept
precision or non-song precision, or a rise in the silent-error rate — the three that represent
*silent* failures. The freeze rule matters more than it looks: a pull request may not add fixtures
and change the algorithm at once, because that makes the before/after incomparable. Fixtures land
first with current numbers recorded; the change lands second.

**D-123 — Auto-accept precision is the primary metric; coverage may be traded down to protect it,
never the reverse.**
A wrong silent pick and an honest miss are not symmetric errors. The miss costs one track and *buys*
trust; the wrong pick costs one track and spends trust across the whole playlist. Auto-accept is the
only band where the system acts without telling the user it made a judgement call, so it is the only
band where an error is a lie rather than a nuisance. This asymmetry is the single principle behind
the threshold placement, the artist gate's existence as a cap, and YouTube's *higher* auto-accept
bar.

**D-124 — The design assumes no audio-analysis endpoint on any provider, now or later.**
Spotify's audio-features and audio-analysis endpoints have been **restricted for new applications**
since November 2024, and Setlistify is a new application. Beyond availability, they would be the
wrong tool: they describe a recording's acoustic character, which answers *"are these two tracks
similar music?"* — a question nobody is asking here. The question is *"is this the same song by the
same artist?"*, which is a metadata question. No signal in §3 depends on audio analysis, and none
should be added if access is ever granted; the honest upgrade path for better matching is richer
*metadata* (prompt 24's MusicBrainz canonical titles and durations), not acoustics.

---

## Out of Scope

| Not in this spike | Why / where it goes |
|---|---|
| **Writing the matcher** | Prompt 14. This document recommends; it contains no implementation code and creates no branch |
| **The job pipeline, the two modes, job state, suspend/resume, progress reporting** | Prompt 13 (spike), then 14 and 17. This document specifies what happens to *one song*; prompt 13 owns everything around it |
| **Which setlist is chosen** ("most recent" vs "most recent substantial") | Prompt 13/14. Matching takes a setlist as given |
| **`Playlist` / `PlaylistTrack` entities and the report's storage shape** | Prompt 14. This document names the `outcome` values and the one schema consequence it forces (the medley segment index, D-114); it does not design the tables |
| **UI for reviewing matches, the report screen, version selection** | Prompt 15 (design) and 16/17 (implementation). §3's banding is the input to prompt 15's "only ambiguous songs need a decision" goal, not its design |
| **The YouTube adapter itself** | Prompt 18. This document gives it a profile shape, a starting threshold set, its two extra mechanisms and its calibration obligation — not its code |
| **Region/availability strategy beyond the resolution/availability split** | Prompt 13's failure taxonomy owns `region_restricted` as an outcome; §8 only establishes that availability must never enter the shared cache |
| **Multi-band concerts** — one playlist or several | Prompt 13. Matching is per-song and indifferent |
| **MusicBrainz canonical titles, durations, alternate-title tables** | Prompt 24. Named repeatedly as the honest upgrade path (D-124), depended on by nothing here |
| **Cross-provider resolution via ISRC** | Recorded in D-119 as the reason the field is carried. Not built, not needed until a third provider exists |
| **Per-user quota on matching calls** | Prompt 22 (entitlement and quota seam). §7 is about the *application's* budget |
| **A backoffice screen for resolutions** | Noted in §8 as a schema the design does not preclude. Prompt 14 may add a read-only list; it is not required |
| **Any change to `StreamingProviderInterface`** | D-71 stands. `TrackCandidate` gains fields (D-119); the port gains nothing |

## Dependencies

**Must be true before prompt 14 implements this**

| Dependency | Provides | Status |
|---|---|---|
| **Prompt 09 merged — setlist.fm integration** | `App\Entity\Song` with `title`, `position`, `setLabel`, `coverOfName`, `coverOfMbid`, `withName`, `info`, `isTape` — every input signal the matcher has. `isTape` in particular was preserved *for this decision* (AC-4.3) | **Met** |
| **Prompt 10 merged — streaming port** | `StreamingProviderInterface::searchTrack()`, `SongQuery`, `TrackCandidate`, the error taxonomy, the tagged-service locator, and `SpotifyTrackMapper::naiveConfidence()` as the one method to replace (D-83) | **Met** |
| **Prompt 11 merged — provider configuration** | `App\Service\Provider\ProviderRegistry` as the runtime read path, so the caller selects a provider without the matcher knowing one exists | **Met** |
| `App\Service\Concert\BandResolver::normalize()` as a PHP service | The artist side of every comparison (D-106) | **Met** |
| **Recorded provider search fixtures** for the eight fixture setlists | §9's harness, which must make zero outbound calls (D-2, D-85) | **To capture** — a deliberate, one-time manual capture, blocking for §9 only |
| **Hand-labelled expected outcomes** for ~200 song entries | The harness's ground truth. One human, one pass | **To do** — the single largest non-code task prompt 14 inherits |
| A Spotify developer application with the developer's account allowlisted | Fixture capture (5-user Development Mode cap) | **Met** for prompt 10; unchanged here |
| Redis and PostgreSQL from `compose.yaml` | §8's two-tier resolution cache | **Met** |
| The architecture test from AC-9.4 | Keeping `App\Service\Matching\` provider-free | **Met** — it already scans `backend/src/` |

**Depended on by**

- **Prompt 13 (pipeline spike)** — consumes the outcome vocabulary (`matched`,
  `matched_low_confidence`, `skipped`, `not_found`, `region_restricted`) and §7's budget arithmetic
  as the input to its "refuse upfront vs stop partway" decision.
- **Prompt 14 (fast mode backend)** — implements all of it, runs §9's harness, and **records the
  tuned thresholds back into this document**.
- **Prompt 15 (playlist flow design)** — §3's banding is what makes "only genuinely ambiguous songs
  need a decision" achievable; §5's report vocabulary is what the report screen renders in plain
  language.
- **Prompt 17 (normal mode)** — owns the `CHOICE` band's interaction, and is the one caller permitted
  a second, on-demand search per song (D-120).
- **Prompt 18 (YouTube adapter)** — inherits the profile shape, the starting numbers and the
  obligation to re-calibrate (D-118).
- **Prompt 24 (rich metadata)** — the path by which the duration signal and canonical titles turn on
  (D-109, D-124).

**Assumptions** *(labelled as assumptions, not verified facts)*

- setlist.fm's medley convention is ` / ` (occasionally ` > `) inside a single song entry, with no
  structural field. **Explicitly to be verified against fixture 7** (D-114); if it is wrong, the
  segmentation rule changes and nothing else does.
- A provider search returns on the order of 20 candidates by default, so the per-song comparison count
  is ~20–25. If a provider's default page is much larger, §2's cost estimate scales linearly and stays
  irrelevant.
- Both providers' search endpoints accept exactly one query per call, with no batch form. Stated from
  current documentation; if a batch form exists, D-120's arithmetic improves and nothing else changes.
- setlist.fm supplies no song duration. Verified against `App\Entity\Song`'s columns, which prompt 09
  derived from real responses — but stated as an assumption about the *upstream API* rather than
  about our schema.
- 20–50 µs per candidate comparison in PHP 8.4 under FrankenPHP. An order-of-magnitude estimate; the
  conclusion (matching is <1% of wall time) survives being wrong by 10×.
- The `æ`/`ø`/`ß`/`ð`/`þ`/`ł` fold set is sufficient for the languages the fixture set covers. It is
  a config-shaped list and will grow.
- Spotify's audio-features restriction for new applications still stands (D-124). If it were lifted,
  the recommendation does not change — the endpoints answer a question nobody is asking.

## Risks and Open Questions

| # | Risk | Impact | Mitigation / decision |
|---|---|---|---|
| R-1 | **Over-engineering** — the temptation the prompt names explicitly | Medium, and insidious: a clever matcher is enjoyable to build and hard to evaluate | Answered up front and in the Recommendation Summary. The design is deliberately plain in its core and invests in normalization, degradation and honesty instead. §2's cost estimate removes the performance excuse for complexity, and §9's harness makes any proposed cleverness prove itself numerically |
| R-2 | **A wrong silent pick** in the auto-accept band | **High and corrosive** — it spends trust across the whole playlist, not just one track | The artist gate as a hard cap (D-109), auto-accept at 0.80 requiring both a strong title and a strong artist, YouTube's *raised* bar (D-118), and auto-accept precision as the primary build-failing metric (D-123) |
| R-3 | **YouTube's 10,000 units/day is the binding launch constraint, not Spotify's user cap** | **Existential for the launch gate** — 2.6 generations/day application-wide on a cold cache | §7 does the arithmetic explicitly rather than leaving it to prompt 18 to discover. The resolution cache (D-121) is designed as the viability mechanism, not an optimization; prompt 18 must gate upfront and request a quota increase immediately |
| R-4 | **The thresholds are guesses** until the harness runs | Medium — a badly-placed auto-accept bar is either noisy or dishonest, and neither is obvious | Stated as guesses everywhere they appear. D-122 makes running the harness and recording the tuned numbers prompt 14's explicit obligation, not an optional follow-up |
| R-5 | **Hand-labelling ~200 entries is real, unglamorous work** that is easy to skip under schedule pressure | High and quiet — without ground truth, every later matching change is opinion | Listed as a blocking dependency with an owner and a size (one human, one day). The fixture set is small *specifically* so this risk is survivable |
| R-6 | **The non-song lexicon misclassifies a real song** | High — a skip disguises itself as deliberate, so nobody investigates | Whole-title exact matching only, position/`setLabel` disambiguation, data-not-code, and a **hard 1.00 precision requirement** in §9 that fails the build. If the lexicon cannot hit it, the lexicon shrinks (D-116) |
| R-7 | **Provider fixture drift** — a search response shape changes and the offline harness stays green | Medium | The same accepted posture as D-70 and D-85: a `@group live` smoke test run manually before a release. CI cannot catch this and D-2 says it must not try |
| R-8 | **The medley convention assumption is wrong** | Low-medium | Explicitly labelled an assumption and assigned to fixture 7 for verification (D-114). If wrong, one splitting rule changes |
| R-9 | **Cache staleness** — a resolution outlives the track it names (deleted YouTube videos are routine) | Medium | Runtime invalidation on `NotFoundException` at insert time is a required behaviour, not a nice-to-have (§8), backed by TTLs and an operator forget-action path |
| R-10 | **Matching logic leaks into an adapter, or a provider concept leaks into `Service/Matching/`** | High — it would make prompt 18 a rewrite, which is the exact failure prompt 10 exists to prevent | The AC-9.4 architecture test already scans `backend/src/`. Per-provider configuration is keyed by a runtime string from `key()`, so no provider name enters PHP source (D-118) |
| R-11 | **Coverage is disappointing on first run** and the instinct is to lower the thresholds | Medium | D-123 states the trade direction in advance: coverage may be traded down to protect precision, never the reverse. Lowering a threshold to hit a coverage number is explicitly the wrong move (D-117) |
| R-12 | **Prompt 14 diverges from this spec silently** because reality disagrees | Medium | Prompt 14's own brief already requires updating these specs in the same branch rather than diverging. The tuned thresholds landing back in this document (D-122) is the concrete instance |

**Open questions — for the user to resolve on approval**

1. **The `CHOICE` band's Fast-mode behaviour (D-110): include-and-flag, or drop?** The recommendation
   is include-and-flag — a 0.7 match is probably right, and the report is honest either way. The
   counter-argument is that a flagged wrong track is still a wrong track in someone's playlist, and a
   stricter product would only ever include what it is sure of. **Recommendation: include and flag.**
2. **Thresholds in configuration rather than the backoffice (D-110).** This is a deliberate departure
   from the project's usual instinct of putting operational knobs in `/admin`, argued in §3. Confirm
   that a threshold change being a reviewed pull request with harness evidence — rather than a click —
   is the wanted trade. **Recommendation: configuration.**
3. **Cover attribution (D-113).** Searching by the original artist means a Johnny Cash setlist returns
   Nine Inch Nails' *Hurt*. Confirm that naming the attribution in the report is a sufficient answer,
   versus spending a second search on covers. **Recommendation: original artist, one search, named in
   the report.**
4. **The fixture set's eighth entry** (a small support act, for the absent-from-catalog case) needs a
   specific real band chosen and its catalog absence verified during capture. Any preference, or leave
   it to the capture session?

---

## Recommendation Summary

**Simple plus honest confidence beats clever, and the arithmetic — not taste — is what settles it.**

The prompt asks whether the over-engineering risk should be called out. It should, plainly:

- **The expensive resource is provider calls, not CPU.** One YouTube search is 1% of the
  application's daily quota; a full cold-cache generation is 38% of it. Matching's own computation is
  under 1% of a generation's wall-clock time. No algorithmic sophistication buys back a call, and no
  performance argument justifies a cheaper metric. **Spend CPU freely, spend calls miserly.**
- **Within one search's twenty-odd candidates, the ceiling on cleverness is low.** The provider's own
  search engine has already done the hard retrieval work. What remains is discrimination among a
  small, mostly-plausible set — a job a weighted heuristic with well-chosen signals does about as
  well as anything more elaborate, and does *legibly*, which matters when the numbers need tuning.
- **The distance between "careful heuristic" and "clever" is small; the distance between either and
  "admits when unsure" is enormous.** A tribute-band recording of "Sæglópur" sitting silently in a
  playlist does not cost one track — it costs confidence in the other twenty-four. That asymmetry
  (D-123) is the single principle organizing every threshold in this document.

So the core is deliberately unexciting: **normalize, one search, score the candidates on a weighted
formula, band the result into three outcomes.** The investment goes into the three things that
actually decide how the product feels — normalization that **extracts rather than strips**, a formula
that **degrades gracefully** when signals are absent (which is the normal case) and **gates hard** on
the one error class that would be a lie, and **three bands that mean different things in each mode**
so Fast mode stays useful and Normal mode asks about three songs instead of twenty-five.

Where this document is expensive, it is expensive on purpose and in exactly two places: the
evaluation harness, and the resolution cache. The first is the only thing that makes every number
here improvable rather than argued about. The second is the only thing that makes YouTube — and
therefore any public launch — arithmetically possible.

---

## Documentation to update *(when prompt 14 implements this, not now)*

This is a spike; it produces this file and nothing else. The list below belongs to the implementing
branch, per `CLAUDE.md`'s mandatory check (`/doc-check`):

- **`docs/architecture.md`** — record **D-106**–**D-124**; extend §8 (the pipeline) with the matching
  stage's real shape; extend §10's data model sketch with `TrackResolution` and `PlaylistTrack`'s
  segment index.
- **`docs/env-vars.md`** *and* **`backend/.env.example`** — the `MATCHING_*` threshold escape hatches,
  documented explicitly as an operational override rather than a tuning mechanism (D-110). Both files
  or neither.
- **`docs/external-apis.md`** — record §7's YouTube arithmetic (3,800 units and ~2.6 generations per
  day, counting inserts, not the searches-only figure) in the §YouTube section and the change log,
  and record the audio-features restriction finding (D-124) under §Spotify.
- **`backend/src/Service/Streaming/README.md`** — note that confidence scoring left the adapter
  (D-83 redeemed) and now lives in `App\Service\Matching\`, with the adapter retaining only signal
  extraction.
- **A new `backend/src/Service/Matching/README.md`** — restating the provider-free rule for the
  directory, in the same spirit as the two existing service READMEs.
- **This document** — the tuned thresholds and the harness's first numbers, written back in (D-122).
- **The OpenAPI spec** — regenerated from prompt 14's API Platform resources. No endpoint is listed
  in any README or in this spec.

---

**Review requested.** This spike proposes decisions **D-106**–**D-124** and nothing is implementable
until it is approved. The four most consequential — and the four most worth disagreeing with — are
**D-110** (three bands meaning different things per mode, with thresholds in configuration rather
than the backoffice), **D-111** (studio as the default version, with the renormalizing fallback
instead of a user toggle), **D-113** (covers searched by the original artist, accepting that a band's
own studio cover loses) and **D-116** (a curated non-song lexicon, defended rather than assumed). The
four open questions above are the only things deliberately left undecided.
