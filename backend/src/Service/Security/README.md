# `Service/Security/`

> `TokenCipher` (libsodium), `AdminAuditLogger`.

Out of scope for this feature — token encryption lands in prompt 10, the admin audit log in
prompt 08.

**Rule to remember when this fills in:** per-user provider OAuth tokens are encrypted at rest
(libsodium `xchacha20poly1305`) through a custom Doctrine type, so a database dump is never a set
of live streaming credentials (`docs/architecture.md` §11). Every backoffice write is audited:
actor, entity, field, old → new, timestamp.
