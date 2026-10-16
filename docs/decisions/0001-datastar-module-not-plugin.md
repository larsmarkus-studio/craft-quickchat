# 0001: Require the Datastar module, not the plugin

**Status:** accepted · 2026-10-16

**Decision:** Depend on `putyourlightson/craft-datastar-module` and bootstrap it lazily (widget render, our controllers). The CP registers the aliased (`data-star-*`) build itself.

**Why:** Works with or without the Datastar plugin installed; datastar.js only lands on pages with the widget; respects a site's own `registerScript => false` (e.g. Datastar Pro).

**Rejected:** Requiring the Datastar plugin (extra install step, script on every page); shipping our own copy of datastar.js.

**Revisit if:** The module's API changes or a site loads a Datastar version incompatible with our attributes.
