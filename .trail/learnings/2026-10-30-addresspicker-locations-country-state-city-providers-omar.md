---
title: "AddressPicker locations: country → state → city providers (world, laravel-countries, custom)"
author: Omar
date: 2026-10-30
branch: main
cost: { minutes: 18, tokens: 185331 }
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
- Two modes per field: free text (default, unchanged) and `->locations(?provider)`. The user explicitly asked to keep both.
- One `database` driver with table/column mapping covers nnjeim/world (countries.status, no states/cities active flag) and altwaireb/laravel-countries (is_active + soft deletes on all three); custom sources implement `Locations\LocationProvider` and are registered as `driver => Class` (gets its entry as `$config`).
- The value stores both the provider key and the name: `stateId`/`cityId` + `state`/`city`. Names keep AddressFilter, exports and free-text mode working and need no lookup to render; ids make the chain and validation exact. On save, names are rewritten from the provider (posted names are untrusted) and unknown keys are dropped.
- Lists travel as `[{value,label}]`, never a map: integer-like object keys are re-sorted by JS, which broke alphabetical city order. KinetixCombobox accepts both shapes plus `fetchOptions` and `valueLabel`.
- Scale: countries in the payload; states whole per country; cities one page (`cities_limit`) then server search. CachedLocationProvider caches unfiltered lists + labels (cache store + per-request memo); KinetixLocations is `scoped()` (Octane-safe). GET endpoint with Cache-Control private; one client cache with in-flight dedupe shared by all pickers.
- A level with nothing to choose (country without states) falls back to text, and validation accepts typed parts only there.

## Tried and ruled out
- Reusing the POST forms/search endpoint: it is model/column shaped and not browser-cacheable.
- Storing only ids: breaks text search (AddressFilter) and anything that displays the JSON.

## Watch out
- nnjeim/world migrations use foreignId() without constrained(): no index on states.country_id / cities.state_id. Documented the indexes to add.
- KinetixCombobox's ComboboxInput prefilled the search with the selected VALUE (e.g. "MX", "14"); fixed with `:display-value="() => ''"`.
- useId() is unique per app: a test mounting two separate apps gets the same id — mount both pickers in one tree.
- Package tests default to the database cache store: set `cache.default=array` in defineEnvironment for anything that caches.
