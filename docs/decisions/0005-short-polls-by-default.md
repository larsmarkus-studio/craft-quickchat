# 0005: Short polls by default, held streams opt-in

**Status:** accepted · 2026-10-16 · supersedes 0003

**Decision:** Widget and CP poll every `pollSeconds` (3) with `data-on-interval`; each request checks once and answers. `holdSeconds` > 0 brings back the held stream from 0003.

**Why:** Small PHP plans have a handful of FPM processes; one held per open chat or dashboard can starve the site. Many Apache + proxy_fcgi setups buffer streams, and their vhost config can't be changed. A few seconds of delay is fine for support chat.

**Rejected:** Held streams by default (process starvation, buffering); a push service (extra infrastructure).

**Revisit if:** Chat traffic makes the per-check query or request rate noticeable, or instant delivery matters; then pub/sub (Mercure, Redis).
