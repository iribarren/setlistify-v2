# `EventSubscriber/`

Not one of `docs/architecture.md` §3's named layers — cross-cutting kernel subscribers live here.

**`SecurityHeadersSubscriber`** (this feature, US-9): applies `X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy` and a `Content-Security-Policy` to every response globally, so
no future endpoint can forget them (AC-9.6). `Strict-Transport-Security` is added only in `prod`
(AC-9.4).
