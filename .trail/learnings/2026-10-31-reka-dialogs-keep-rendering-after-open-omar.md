---
title: "Reka dialogs keep rendering after open=false — don't reset visual state in the open watcher"
author: Omar
date: 2026-10-31
branch: main
cost: { minutes: 4, tokens: 105784 }
touches:
  - AGENTS.md
  - ".kiro/settings/mcp.json"
  - ".kiro/steering/trail.md"
  - CHANGELOG.md
  - resources/js/components/KinetixLightbox.vue
  - tests/js/components/KinetixLightbox.spec.ts
  - src/Forms/Components/AddressPicker.php
  - config/kinetix.php
  - docs/forms.md
  - gallery/messages.en.json
---

## Decided
- KinetixLightbox keeps `closing` set when `open` turns false; `startOpening()` clears it. Fixed in v0.272.2.

## Watch out
- Reka's Presence keeps a closed DialogOverlay/DialogContent mounted until its exit animation ends (`data-[state=closed]:animate-out`). Any state the template reads, such as an inline opacity or chrome-hidden classes, is still painted during that fade.
- Resetting that state in a `watch(() => props.open)` makes the overlay jump back to full opacity mid-fade. The user sees this as a flicker right after closing.
- happy-dom unmounts immediately (animationName 'none'). To test exit-time state, stub `window.getComputedStyle` to return a different animationName for `data-state="closed"` nodes; see the "fades out after flying home" spec in KinetixLightbox.spec.ts.
