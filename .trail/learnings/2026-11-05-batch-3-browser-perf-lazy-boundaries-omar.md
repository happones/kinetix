---
title: "Batch 3 browser perf: lazy boundaries, polling helper, Echo loader, stylesheet edits"
author: Omar
date: 2026-11-05
branch: main
cost: { minutes: 71, tokens: 196587 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/.vitepress/config.ts
  - docs/announcements.md
  - docs/comments.md
  - docs/confidential.md
  - docs/cookie-consent.md
  - docs/forms.md
---

## Decided
- Optional bodies load through `defineKinetixLazy` (`composables/kinetixLazy.ts`).
  - Entries are defined at module scope in loader `.ts` files that have no static `.vue` imports: Form fields, Table filters, Table cells, modal bodies, layout bodies.
  - `.component` is a stable outer wrapper. It retries twice, recovers once a later download succeeds, forwards attrs, slots, vnode hooks and template refs, and renders the real markup on SSR.
  - Read it once per instance (by stable key).
- Only six field types stay static: text-input, select, checkbox, toggle, textarea, hidden. A select's Combobox/Select control is itself lazy.
- Filters load on intent (hover/focus/open of the Filters button). Input cells load at table setup, by column type.
- Layout hosts are shells (`useKinetixLazyOnDemand` / `useKinetixLazyWhenEnabled`). They keep triggers synchronous: Cmd+K, `?`, preview and import events. The enable flags come from shared props (`kinetix_config.spotlight.enabled`, `realtime`).
- Every poll goes through `useKinetixPolling`: chained timeouts, no overlap, pauses while hidden, an optional run timeout. The bell's reload task settles even when a `before` listener cancels the visit.
- echo-vue loads dynamically through `kinetixEcho.ts`, only when realtime is on and Echo is configured. This also fixed the bell crashing on apps without Echo.
- Host stylesheet edits go through `Support\StylesheetLines`.
  - It skips comments and strings and tracks brace depth.
  - It anchors after the last top-level `@import`/`@source`, handles BOM and `@charset`, and keeps line endings.
  - If there's no safe place it writes nothing.
- Bundle-guard specs (`tests/js/bundleGuard.*.spec.ts` over `tests/js/support/staticImportGraph.ts`) enforce the boundaries.

## Tried and ruled out
- Making the icon registry lazy only for the layout callers (KinetixAlert, TeamAvatar). Tables, actions and infolists still import it statically, so every page paid about +11 KB gz in about 23 single-icon chunks. Reverted: the registry stays in the entry, loaded once.
- Setup-time preload of filter inputs: it downloads the date pickers on every table that has a date filter.
- Anchoring stylesheet inserts on "a line ending with `;`". The installer's own Sonner line ends with a comment, so the anchor slid inside `@theme {}`.
- ComboboxVirtualizer for the timezone picker: it doesn't support region groups.

## Watch out
- Measuring needs a real host build. Use the laravel-template worktree, the stats plugin and `scratchpad/scripts/compare2.py`.
- Moving shared code out of the entry can raise page deltas: Combobox/Select left the entry and every form paid for it.
- Rolldown splits modules shared between eager layout code and lazy bodies into tiny chunks; each is a separate gzip response. The optional host `codeSplitting` group in docs/performance.md merges them. It needs `includeDependenciesRecursively: false`, or `public.ts` balloons.
