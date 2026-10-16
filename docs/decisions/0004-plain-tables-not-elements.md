# 0004: Plain tables, not Craft elements

**Status:** accepted · 2026-10-16

**Decision:** Conversations and messages are rows in two plugin tables, queried with `craft\db\Query`.

**Why:** Chats are private, never need fields, search, drafts or element indexes; elements would cost several queries per message.

**Rejected:** Custom element types; entries in a section.

**Revisit if:** Chats need custom fields, element exports or relations to users/orders.
