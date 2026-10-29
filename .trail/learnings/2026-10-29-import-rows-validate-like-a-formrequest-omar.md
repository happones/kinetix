---
title: Import rows validate like a FormRequest; piped rules no longer kill the job
author: Omar
date: 2026-10-29
branch: main
cost: { minutes: 16, tokens: 302353 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/import-export.md
  - gallery/specimens.ts
  - gallery/stubs/http.ts
  - resources/boost/skills/kinetix-import-export/SKILL.md
  - resources/js/components/KinetixImporter.vue
  - resources/js/stores/kinetixNotifications.ts
---

## Decided
- The per-row pipeline lives in `Importer::processRow()` (prepareRow → record() once → validateRow → importRow); `ImportProcessor` only maps, wraps each row in a savepoint and counts. Everything a row throws (incl. a bad rule name) fails THAT row.
- `ImportColumn::rules()` splits piped strings like Laravel's ValidationRuleParser (also inside lists). A string stored whole became a rule literally named `required|string` → BadMethodCallException outside the old try → the job died and the user never got a notification. Empty cells hid it (non-implicit rules skip nulls).
- `$validateUsing` lives on the Importer, not on ImportAction: the action only carries a signed class token, and the worker rebuilds the importer from it.
- FormRequest on the worker: synthetic Request + bound Route (record as `snake(model)` and `record`), user = recipient, `prepareForValidation()` + `getValidatorInstance()` via Closure::call; never authorize().
- Column labels beat a FormRequest's attributes() in messages: users fix the header they see in their file.
- Savepoint per row is default-on with config `kinetix.imports.transaction_per_row` + per-importer `$transactionPerRow` (user's default-on + escape hatch preference).

## Tried and ruled out
- Validating after castStateUsing(): would break existing casts that return non-strings; prepareRow() is the normalize-before-validate hook instead.
- `[...$columnRules, ...$rules()]`: string keys overwrite, so a field's row rules replaced its column rules. Call addRules() once per set.

## Watch out
- Test fixtures: `where('number', null)` is whereNull — a resolveRecord without the key matched an existing row and turned inserts into updates.
- The modal importer cap must sit on the ScrollArea VIEWPORT (`viewportClass`); on the root, viewport `size-full` resolves to auto and the list covers Back/Continue. Reproduced and verified with the `importer-many-fields` gallery specimen.
- Translations of `kinetix.*` aren't loaded in the package TestCase: assert reasons against the failed-rows CSV, not the notification body.
