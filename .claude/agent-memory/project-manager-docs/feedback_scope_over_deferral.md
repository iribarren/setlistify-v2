---
name: feedback-scope-over-deferral
description: When a capability would actually fix the reported bug, the user wants it in scope now rather than deferred to a follow-up spec — and reversals are amended in place
metadata:
  type: feedback
---

When a spec's recommendation is "defer this to its own document", check first whether the deferred
part is what actually fixes the reported problem. If it is, expect the user to pull it into scope.

**Why:** on `docs/specs/2026-08-27-instant-setlist-refresh.md` (2026-08-27) I recommended against
letting users resolve an `ambiguous` band (D-268), on blast-radius grounds, which shipped a feature
that *explained* the reported Boikot bug without fixing it. The user overruled it and asked for the
capability, with the same rigor applied to the safeguards instead of to the deferral. The blast-radius
concern was legitimate — the answer was to design safeguards (narrower-than-operator write, bounded
input, one-shot), not to postpone.

**How to apply:**
- Deferral is a real option, but justify it on *cost of building*, not on *risk*, when the risk can be
  designed against. A recommendation to defer should say what safeguards would make it safe now.
- Reversing a decision is an **in-place amendment**: keep the superseded decision with a
  "Superseded by D-nnn" note explaining what is still true about its reasoning, continue the global
  D-numbering, and add an *Amendment history* table at the end. Never delete or rewrite a decision.
  This mirrors the cross-document rule in [[spec-instant-setlist-refresh-decisions]] and
  [[spec-house-style]].
- When open questions are answered, rewrite the block as "resolved" with the reasoning for each
  answer — do not leave stale recommendations that contradict the body.
