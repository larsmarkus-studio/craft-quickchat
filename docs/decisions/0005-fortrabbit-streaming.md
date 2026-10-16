# 0005: Long-held SSE on fortrabbit

**Status:** open · 2026-10-16

**Question:** fortrabbit runs Apache → `proxy_fcgi` → PHP-FPM with gzip on for text types, 2 PHP processes on XS/SM (12 max), no vhost access (so no `flushpackets`). Each 30 s stream holds a process and may arrive buffered in one burst.

**Options:** (a) short SSE polls: every few seconds a Datastar request that answers at once, never holding a process; (b) keep long-held streams and test whether fortrabbit flushes; (c) push from a separate service (Mercure, Pusher).

**Leaning:** (a), with the hold time as a setting so a host that streams well can still use long-held SSE.

**Revisit if:** a staging test on fortrabbit shows streams arrive live, or the plan has workers to spare.
