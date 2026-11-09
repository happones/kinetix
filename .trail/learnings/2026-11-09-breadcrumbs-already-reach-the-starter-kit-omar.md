---
title: Breadcrumbs already reach the starter kit layout; record reference design
author: Omar
date: 2026-11-09
branch: main
cost: { minutes: 36, tokens: 328439 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - docs/breadcrumbs.md
  - docs/resources.md
  - gallery/ResourceShowPage.vue
  - gallery/messages.en.json
  - gallery/specimens.ts
  - gallery/stubs/http.ts
  - resources/boost/skills/kinetix-resources/SKILL.md
  - resources/js/components/KinetixPageHeader.vue
---

## Decided
- Inertia v3 (@inertiajs/vue3 >= 3.0.0) renders every layout with `{ ...page.props, ...layout.props, ...shared }`, so a page's `breadcrumbs` prop already reaches the starter kit's AppLayout with no `defineOptions`. Generated pages still pass it explicitly via `defineOptions({ layout: (props) => ({ breadcrumbs: props.breadcrumbs ?? [] }) })`, the starter kit's own pattern. Never wrap the page in `<AppLayout>`: the default layout already wraps it.
- Record reference (v0.281.0): `Resource::$recordReferenceAttribute` / `recordReference(): ?RecordReference` → `getRecordReference($record)` (RecordReferenceData: visible, position, label, copy). The raw value is NOT serialized; `copy` is everything that reaches the browser. Label = `getModelLabel()` (never the table name), `label()` is translated.
- KinetixPageHeader prop is `recordReference` (not `reference`: KinetixComments/KinetixTags use `reference` for a sealed string; `Support\RecordReference` is that internal token).
- useKinetixRecordModals guards every resolve with a request counter bumped on open AND close; close/create also reset `isLoading`.

## Tried and ruled out
- Claiming generated pages "never showed breadcrumbs" — the reviewer SSR-rendered both shapes on 3.0.0 and 3.4.0: identical output.
- `truncate` on the reference label: the header column shrank it to "P…". Use nowrap label + `break-all` mono part.

## Watch out
- `pkill -f "<pattern>"` inside a compound Bash command matches its own shell and kills it (exit 144). Kill dev servers in a separate call.
- Playwright `navigator.clipboard.readText()` hangs on a permission prompt; stub `writeText` in the page to capture what was copied.
