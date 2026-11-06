---
title: "Test suite audit: bcrypt cost, filler patterns to avoid"
author: Omar
date: 2026-11-06
branch: main
cost: { minutes: 20, tokens: 24792 }
touches:
  - AGENTS.md
  - phpunit.xml
  - resources/js/components/KinetixSheet.vue
  - tests/Feature/ActionShortcutTest.php
  - tests/Feature/BillingManagerTest.php
  - tests/Feature/CopyableTest.php
  - tests/Feature/FormFieldAuthorizationTest.php
  - tests/Feature/InfolistEntryAuthorizationTest.php
  - tests/Feature/MediaLibraryTest.php
  - tests/Feature/SpotlightDiscoveryTest.php
---

## Decided
- `phpunit.xml` sets `BCRYPT_ROUNDS=4`. Bcrypt at Laravel's default cost 12 was about 65% of the PHP suite's runtime (the 139 password, credentials, confidential and identity tests took 41 s). No test depends on the cost.
- The 2026-11-06 audit of ~4970 tests found both suites mostly clean: 4 deletes, 9 merges and 13 rewrites, all applied. A trait is tested once, directly (`HasAuthorizationTest`), not through every component that uses it. Field-condition operators live only in the shared PHP/JS fixture `tests/js/fixtures/field-conditions.json`.

## Tried and ruled out
- Collapsing the ~180 PHP serialization pairs ("flag set" plus "defaults off") into one test per component: too large a change for little gain. They guard the hand-mirrored `kinetix.ts` contract.

## Watch out
- Don't select by style classes in specs (`.shadow-lg`, `.bg-black/80`); add `data-slot`/`data-side` hooks, as KinetixSheet now has.
- Avoid asserts that pass vacuously: `get()` followed by `toBeTruthy`, `.resolves.not.toThrow()`, `assertTrue(true)`, and loops over a possibly empty list.
- Specs that only check a text shouldn't mount scale fixtures: 24 reka selects cost about 1.5 s per test. Stub `Intl.supportedValuesOf` with a short zone list.
- Use `createStub`, not `createMock`, when no expectations are set (it avoids PHPUnit notices).
