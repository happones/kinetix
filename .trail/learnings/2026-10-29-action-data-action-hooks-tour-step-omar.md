---
title: "Action data-action hooks; tour step i18n keys, versions and automation skip"
author: Omar
date: 2026-10-29
branch: main
cost: { minutes: 13, tokens: 153458 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/actions.md
  - docs/tours.md
  - gallery/messages.en.json
  - gallery/specimens.ts
  - gallery/stubs/inertia.ts
  - resources/boost/skills/kinetix-onboarding/SKILL.md
---

## Decided
- Every rendered action binds `actionAttributes(action)` (composables/kinetixActionAttributes.ts): `data-action=<name>` + `Action::extraAttributes()` minus `on*`. It is a public contract for tours/E2E — apps were patching published components to add it and composer updates wiped the patch.
- Tour copy: `TourStep::titleKey()/descriptionKey()` translate inside `toArray()`, which runs in the `kinetix_tours` Inertia share closure — after the locale middleware. `__()` in a provider's boot() translates in the app's language.
- `Tour::version(n)` stores "seen" as `id@vN` (v1 = bare id, so existing state survives); the controller stores `seenKey()`, the store reads `tour.seenKey ?? id`.
- `skip_when_automated` (default on) gates only auto-start (`matchFor`), never manual `start()`. Opt-in: `?kinetix-tours=1` or `localStorage['kinetix.tours.automated']='1'`.

## Tried and ruled out
- Binding actionAttributes on WidgetHeaderActions: widget header links aren't Actions (no name; vue-tsc rejects the type).

## Watch out
- happy-dom reports `navigator.webdriver = true` (like Playwright): tour specs about a person must pin it false or auto-start silently stops.
- The Playwright MCP browser reports `navigator.webdriver = false` (stealth); a stock `chromium.launch()` reports true. Verify the automated path with a plain Playwright script, not the MCP browser.
