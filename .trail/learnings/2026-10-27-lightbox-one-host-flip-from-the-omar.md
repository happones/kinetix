---
title: "Lightbox: one host, FLIP from the thumbnail, dark-scoped tokens"
author: Omar
date: 2026-10-27
branch: main
cost: { minutes: 23, tokens: 275885 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - docs/.vitepress/config.ts
  - docs/actions.md
  - docs/help-center.md
  - docs/infolists.md
  - docs/markdown.md
  - docs/tables.md
  - gallery/messages.en.json
---

## Decided
- One viewer for every image: `<KinetixFilePreview />` (already in host layouts) is the lightbox host. Entry points: `kinetix:preview` event (single-file AND `{items,index,options}`), `openKinetixLightbox()` (falls back to a new tab when unhandled) and click delegation on `[data-kinetix-lightbox="group"]`. Host props = app-wide defaults, declared with `undefined` defaults so an omitted Boolean prop doesn't cast to false and override the viewer's own default.
- The viewer content carries `class="dark"`: the host's `.dark { --token }` rules apply to any element, so plain ghost buttons read correctly on the black scrim in both themes — no raw white/black on controls.
- Open/close zoom is a WAAPI FLIP on the `<img>` from `item.origin` (uniform scale + centered clip-path inset so object-cover thumbnails match). Close uses `fill: forwards` and emits `update:open(false)` after `finished`.
## Tried and ruled out
- A local fallback lightbox inside KinetixMarkdownContent: its three v-html roots can't take a sibling, and an extra wrapper changes the DOM other code reads. New-tab fallback instead.
## Watch out
- Vitest afterEach hooks run last-registered-first: register `document.body.innerHTML = ''` BEFORE `enableAutoUnmount(afterEach)` or unmounting teleported dialogs throws `insertBefore of null` and every test fails.
- `useKinetixAnnounce` writes on the next animation frame — await a rAF before asserting the live region.
- scripts/screenshots.mjs and `npm run gallery:dev` both use port 5733; stop the dev server first.
