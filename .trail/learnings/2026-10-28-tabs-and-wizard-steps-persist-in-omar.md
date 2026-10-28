---
title: Tabs and wizard steps persist in the query string by default
author: Omar
date: 2026-10-28
branch: main
cost: { minutes: 27, tokens: 245269 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/forms.md
  - docs/infolists.md
  - docs/wizard.md
  - gallery/specimens.ts
  - gallery/stubs/inertia.ts
  - resources/boost/skills/kinetix-forms/SKILL.md
---

## Decided
- Form tabs, infolist tabs and form wizards keep their state in the URL (`?tab=`, `?step=`) ON by default (user asked: default behavior, switchable via config). Switches `kinetix.forms.query_string.tabs|steps`, read with `config(..., true)` so published configs without the keys stay on. Per group `Tabs::persistInQueryString('name'|false)`, `Wizard::persistStepInQueryString('name'|false)`.
- The server decides: containers emit `queryParam`, children `urlKey` (`Tab/Step::key()` → slug of label → `tab-N`, deduped) via `Support\QueryStringKeys`. The client only reads/writes through `useKinetixQueryParam` (also used by relation managers' `?relation=`).
- Nested tab groups share one param: a group matches its own key OR a descendant group's key, so `?tab=address` opens outer + inner.
- Wizard never opens past a step failing `beforeNext` (required fields empty) and rewrites the URL to the step actually shown.
## Tried and ruled out
- Opt-in per component (first proposal): the user wanted it default-on with a config off switch.
- Pushing history entries per tab: Back would walk tabs; replace only.
## Watch out
- `flat` is the "inside a modal" signal for forms/infolists; modal-hosted tabs/wizards must never write the URL.
- `KinetixInfolistEntries` can mount with no `schema` — guard `(props.schema ?? [])` in setup code.
- Gallery `router` stub needs `replace()` doing `history.replaceState`, or URL writes silently fall back.
