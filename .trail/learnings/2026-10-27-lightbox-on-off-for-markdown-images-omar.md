---
title: "Lightbox on/off for Markdown images: config switch + prop, still one host"
author: Omar
date: 2026-10-27
branch: main
cost: { minutes: 6, tokens: 38167 }
touches:
  - AGENTS.md
  - ".kiro/settings/mcp.json"
  - ".kiro/steering/trail.md"
  - ".agents/skills/kinetix-development/SKILL.md"
  - CHANGELOG.md
  - config/kinetix.php
  - docs/help-center.md
  - docs/lightbox.md
  - docs/markdown.md
  - resources/boost/skills/kinetix-lightbox/SKILL.md
---

## Decided
- Markdown images in the lightbox are ON by default and switchable: `kinetix.lightbox.markdown` (env KINETIX_LIGHTBOX_MARKDOWN), shared as `kinetix_config.lightbox.markdown`, read with `useKinetixMarkdownLightbox()`. `KinetixMarkdownContent`, `KinetixHelpArticle` and `KinetixDocs` take a `lightbox` prop that wins over the config both ways (v0.259.0).
- This does NOT bring back the ruled-out local viewer inside KinetixMarkdownContent: the component still only dispatches to the single `<KinetixFilePreview />` host. trail check flags it on keywords alone.
## Watch out
- An "unset" Boolean prop must be declared with an explicit `undefined` default in withDefaults, otherwise Vue's Boolean casting turns a missing prop into `false` and the config never applies.
