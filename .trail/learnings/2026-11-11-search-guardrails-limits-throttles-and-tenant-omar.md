---
title: "Search guardrails: limits, throttles and tenant-safe modifiers"
author: Omar
date: 2026-11-11
branch: main
cost: { minutes: 64, tokens: 182340 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/.vitepress/config.ts
  - docs/forms.md
  - docs/relation-managers.md
  - docs/tables.md
  - resources/boost/skills/kinetix-forms/SKILL.md
  - resources/boost/skills/kinetix-tables/SKILL.md
---

## Decided
- One `kinetix.search` config (max_length 100 truncates, min_chars 1, throttle 120/min, relation_throttle 60/min; null disables). `SearchTerm::from()` normalizes every lookup term (control chars → one space, cap, ints/floats cast).
- min_chars only bounds selects, select filters, checkbox lists and relation pickers (server answers `meta.below_min` without querying). Tags, help, spotlight and table search have their own rules.
- Remote search modifiers are applied through `SearchScope` as a closure global scope (`withGlobalScope`), so Eloquent's callScope nests their wheres in their own group when they contain an OR, while their joins/orderBy still apply. A bad modifier class throws at definition time (fail closed).
- `SelectFilter::searchScope()` + modifier bound both the search endpoint and `resolveSelectedOptions()` (selected labels) — the label lookup was the cross-tenant leak.
- Client: `kinetixLatest` (abort + sequence) is the guard for every debounced lookup; the min_chars cut only applies on the searchToken path, never to a host `fetchOptions`.

## Tried and ruled out
- Wrapping the modifier in `$builder->where(fn ...)`: isolates `orWhere` but silently drops the modifier's joins and ordering (BC break for Select::relationship modifiers).
- A single "one search at a time" lock: the aborted request keeps running in PHP and holds it, so the newest gets the 429 — P1 uses N slots instead.

## Watch out
- Lang strings read by vue-i18n use `{count}`, not Laravel's `:count`.
- Don't tell hosts to re-publish config with --force; mergeConfigFrom already supplies new keys.
- Filter tokens minted before an upgrade stay unscoped until token_ttl expires.
