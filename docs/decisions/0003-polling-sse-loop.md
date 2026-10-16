# 0003: SSE loop polling the DB, 30-second requests

**Status:** superseded by 0005 · 2026-10-16

**Decision:** Each stream request polls the DB every second for `streamSeconds`, then re-renders its `data-init` element so Datastar opens the next one.

**Why:** Zero infrastructure on shared PHP hosting; requests stay well under proxy timeouts.

**Rejected:** Redis pub/sub or a separate SSE server (extra services); short-interval `@get` polling (no push).

**Revisit if:** Concurrent chats approach the PHP-FPM worker count. It did: see 0005.
