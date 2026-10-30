---
title: "Typed export cells, ordered option payloads and per-page settings access"
author: Omar
date: 2026-10-30
branch: main
cost: { minutes: 17, tokens: 316633 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - docs/forms.md
  - docs/import-export.md
  - docs/settings.md
  - resources/boost/skills/kinetix-forms/SKILL.md
  - resources/boost/skills/kinetix-import-export/SKILL.md
  - resources/boost/skills/kinetix-settings/SKILL.md
  - resources/js/components/KinetixCheckboxList.vue
  - resources/js/components/KinetixCombobox.vue
---

## Decided
- Export cell types live on ExportColumn (`date($excelFormat)`, `numeric(?$format)`, `text()`), travel as `Exporter::cellTypes()` into `FileWriter::withCellTypes()`. xlsx/xls get explicit cell types + a number format per column range (rows 2..n; the header is never typed); csv/pdf get `NumberFormat::toFormattedString()` text. A value that can't be its type is written as-is (the summary row's "Sum: 10" lands in a numeric column).
- `xls` is just `Writer\Xls` in FileWriter's close(); download names use the format as extension, nothing else to wire.
- Options keep order via `Support\OptionList::forWire()`: a map is sent unchanged unless a JS object would enumerate it differently (array-index keys first, ascending); then it becomes `[{value,label}]`. Applied at Field::toData, Filter/Column toData and SearchController. Frontend reads both shapes through `kinetixOptionList()` (composables/kinetixOptions.ts) — Radio, CheckboxList, FilterSelectField, Combobox.
- SettingsPage::ability() (default 'settings.manage', array = any) + authorize(); the controller lists/serves only allowed pages, index 403 when none (404 only when no page is registered).
- Import modes: `resolveRecord()` returning an unsaved model counts as no match (`$record->exists`).

## Tried and ruled out
- Always sending option lists: breaks host components that read `options` as a map for no gain when JS keeps the order.
- A `match(true)` with `&&` arms in FileWriter: Pint realigns it forever (known gotcha) — plain if/elseif.

## Watch out
- The ternary filter's `['1' => yes, '0' => no]` was silently reordered to No/Yes in the browser; it now travels as a list (FiltersTest expects the list).
- PhpSpreadsheet's default binder already keeps "06700" as a string in xlsx; the leading-zero loss users see is CSV reopened in a spreadsheet — only `text()` + xlsx/xls fixes that.
- KinetixTours: type driver.js's import locally (`as unknown as {driver: DriverFactory}`) — hosts may have a narrower `declare module 'driver.js'` shim.
