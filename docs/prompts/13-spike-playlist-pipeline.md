# 13 — SPIKE: playlist generation pipeline

**Command:** `/spec spike-playlist-pipeline` · **Agent:** `backend-engineer` · **Depends on:** 09, 10, 11, 12

## Goal
A design document for the machinery that turns a tracked concert into a real playlist: the job
pipeline, how Fast and Normal mode share it, what happens when each of the many failure modes fires,
and how a half-finished generation resumes rather than restarts.

**This prompt produces a document, not an implementation.** Use `/spec`.

## Context
Prompt 12 answers "which track is this song?". This prompt answers everything around it: orchestration,
state, failure and recovery.

The shape from `docs/architecture.md` §8:

```
Concert ─► select Setlist ─► normalize Songs ─► match Tracks ─► create Playlist ─► report
            (auto│user)                          (auto│user)
```

Two constraints drive the design. First, generation is **slow** — a 25-song setlist means dozens of
provider calls, far past a sane HTTP timeout, so it must be asynchronous. Second, failure is the
**normal case**, not the exception: bands with no setlist.fm data, songs absent from a catalog, quota
exhaustion mid-run. A pipeline that treats these as errors will feel broken while working correctly.

## Scope of the investigation
- **Job model**: a `PlaylistGenerationJob` entity — states, transitions, per-song progress, and how
  the client observes it (polling vs SSE vs websockets; recommend one, considering that Expo web and
  native differ).
- **How the two modes share one pipeline.** They differ only in who resolves ambiguity. Specify the
  shared stages and the exact points where Normal mode suspends for user input.
- **Suspend and resume.** Normal mode may sit unanswered for hours or days. How is partial state
  persisted, how long does it live, and what happens if the underlying setlist or catalog changed in
  the meantime?
- **A complete failure taxonomy**, each with a decided user-facing behaviour:
  band unknown to setlist.fm · band known but no songs recorded · setlist.fm daily budget exhausted ·
  song absent from catalog · only live/cover versions available · region-restricted track ·
  provider rate limit · provider quota exhausted mid-run · token expired mid-run · provider disabled
  mid-run (prompt 11) · playlist created but only partially filled · network failure mid-run.
- **Partial success as a first-class outcome.** A playlist with 14 of 19 songs is a *success* with a
  report, not a failure. Specify how that report is structured and stored — `PlaylistTrack.outcome`
  per `docs/architecture.md` §10.
- **Idempotency and retries.** Retrying a failed job must not create a second provider playlist or
  duplicate tracks. Specify the idempotency key and the safe retry boundaries.
- **Ordering**: setlist order is meaningful (it is the show). Confirm order is preserved through
  matching and provider insertion, including when tracks are missing.
- **Provider quota interaction**: a generation that would exhaust YouTube's daily budget — refuse
  upfront, or start and stop cleanly partway? Recommend one.
- **Concurrency**: several users generating at once, or one user generating for a multi-band concert.
  Worker count, per-provider fairness, and how one user cannot starve the rest.
- **Naming and metadata**: what the provider-side playlist is called and described, and whether the
  user can change it.

## Out of scope
- The matching algorithm — prompt 12.
- Implementation — prompt 14 (fast mode) and 17 (normal mode).
- UI — prompt 15.

## Acceptance criteria
- [ ] A written design exists in `docs/specs/`, complete enough to implement prompts 14 and 17 without
      further design work.
- [ ] The job state machine is specified, with every state and transition named.
- [ ] **Every failure mode listed above has a decided behaviour** — none deferred.
- [ ] Suspend/resume for Normal mode is fully specified, including expiry and stale-data handling.
- [ ] Partial success is defined as a success path, with the report structure specified.
- [ ] Idempotency is specified: retrying cannot duplicate a playlist or its tracks.
- [ ] Progress reporting mechanism is chosen, with reasoning covering both Expo web and native.
- [ ] Quota-exhaustion behaviour is decided for both setlist.fm and the streaming provider.
- [ ] Multi-band concerts are addressed: one playlist or several, and in what order.

## Risks & open questions
- Multi-band concerts are an under-specified part of the original brief and deserve a real answer
  here. A festival with eight bands is a very different generation than a single headliner.
- Long-lived suspended Normal-mode jobs will accumulate. Specify expiry, or they become a data-growth
  problem nobody notices until they are one.
- Progress reporting on Expo across web and native is a genuine constraint, not a detail — polling may
  be the pragmatic answer even if it is less elegant.
- Be wary of designing a general-purpose workflow engine. Two modes and one pipeline is the
  requirement; anything more is speculative.
