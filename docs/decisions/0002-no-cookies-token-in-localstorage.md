# 0002: No cookies; token in localStorage, POST only, no CSRF

**Status:** accepted · 2026-10-16

**Decision:** The visitor is identified by a random 32-char token kept in `localStorage`, sent in POST bodies. Visitor endpoints disable CSRF validation.

**Why:** No cookie banner needed for chat; the token never lands in URLs or logs. CSRF guards ambient credentials, and there are none here.

**Rejected:** Craft session/CSRF cookie (cookies); token in GET query (leaks into logs).

**Revisit if:** Visitors need to continue across devices; then email a magic link.
