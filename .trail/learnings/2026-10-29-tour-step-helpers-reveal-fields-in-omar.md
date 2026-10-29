---
title: Tour step helpers reveal fields in closed tabs and wizard steps
author: Omar
date: 2026-10-29
branch: main
cost: { minutes: 11, tokens: 96156 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - docs/tours.md
  - gallery/specimens.ts
  - gallery/stubs/inertia.ts
  - resources/boost/skills/kinetix-onboarding/SKILL.md
  - resources/js/components/KinetixFormSchema.vue
  - resources/js/components/KinetixFormTabs.vue
  - resources/js/components/KinetixFormWizard.vue
---

## Decided
- `TourStep::field()/tab()` carry a `reveal` request; the host broadcasts `kinetix:tour-reveal` and each tab group / wizard / infolist opens the child holding the target (`holdsTourTarget`), so no `->inTab()` declaration is needed. Repeated up to 10 × 40 ms because nested groups mount only after their parent tab opens.
- A wizard never opens a step past one whose required fields are empty (same rule as `?step=`); it answers with a fallback selector — the step's indicator — instead.
- Navigation goes through driver.js `onNextClick`/`onPrevClick` + `moveTo()`; on the last step driver routes Done to onNextClick (no onDoneClick set), so going past the end calls destroy().
- Tabs/steps are keyed even when the URL param is off (tours need the keys).

## Tried and ruled out
- driver.js's own `waitForElement` / `skipMissingElement`: 1.8 has them, but `kinetix:install --tours` allows ^1.3, so host apps may lack them.

## Watch out
- reka-ui unmounts closed TabsContent: a field in a closed tab is NOT in the DOM, so a plain selector can never find it.
- Test driver mocks must track the active index (drive/moveTo set it), or onNextClick keeps re-driving step 0.
