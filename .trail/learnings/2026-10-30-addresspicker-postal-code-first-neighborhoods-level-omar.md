---
title: "AddressPicker postal-code-first: neighborhoods level and code lookup"
author: Omar
date: 2026-10-30
branch: main
cost: { minutes: 14, tokens: 142913 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/forms.md
  - gallery/messages.en.json
  - gallery/specimens.ts
  - gallery/stubs/http.ts
  - resources/boost/skills/kinetix-forms/SKILL.md
  - resources/js/components/Form/KinetixFormField.vue
  - resources/js/components/KinetixAddressPicker.vue
---

## Decided
- Postal codes are an optional capability, not new LocationProvider methods: `Locations\PostalCodeProvider extends LocationProvider` (postalCode/neighborhoods/neighborhood), so existing custom providers keep compiling. Capability is checked with instanceof, which is why the database driver splits into `DatabasePostalCodeProvider` (chosen when the entry maps `postal_codes`) and the cache into `CachedPostalCodeProvider` (chosen when the inner provider implements it). `KinetixLocations::postalCodes($name)` returns it or null.
- `neighborhood` is an opt-in part: `AddressParts::FIELDS` lists every part, `AddressPicker::FIELDS` stays the old six defaults, `PARTS` is what fields()/requiredFields() accept. normalize() only adds `neighborhood`/`neighborhoodId` keys when the picker shows the part, so stored JSON of existing pickers is unchanged.
- On save the neighborhood's name AND postal code come from the provider (a colonia has one CP); an unknown postal code is not a validation error (catalogs lag) — the levels stay choosable by hand.
- postalCodeFirst() reorders parts only when fields() wasn't used; asking for it from a provider without postal codes throws LogicException in toData (fail loudly, like import modes).
- Frontend: lookup is triggered by typing (input handler, 350 ms debounce, 3+ chars), never by value changes, so picking a neighborhood that fills the code doesn't re-query. The neighborhood list is the code's while its city is the chosen one, otherwise the city's.

## Watch out
- Specs of a controlled component must feed `update:value` back (`'onUpdate:value': v => w.setProps({value: v})`); otherwise a debounced patch merges into the stale prop and drops what was typed.
- Gallery stubs match URLs by regex substring: `code=44160` also matches `code=4416044150`.
- `eslint resources/js` can die on the testbench vendor symlink loop (ELOOP); lint the changed files directly.
