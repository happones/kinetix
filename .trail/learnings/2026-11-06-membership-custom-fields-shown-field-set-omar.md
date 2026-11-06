---
title: "Membership custom fields: shown-field set, merge on edit, strict options"
author: Omar
date: 2026-11-06
branch: main
cost: { minutes: 44, tokens: 95342 }
touches:
  - AGENTS.md
  - CHANGELOG.md
  - config/kinetix.php
  - docs/membership.md
  - docs/performance.md
  - gallery/messages.en.json
  - gallery/specimens.ts
  - gallery/stubs/http.ts
  - resources/boost/skills/kinetix-membership/SKILL.md
  - resources/js/components/Form/KinetixFieldRequirements.vue
---

## Decided
- `membership.fields` = class-string of a `MemberFields` subclass (schema/values/apply). Pending values live in `kinetix_member_provisions.fields` (JSON, migration 000053, opt-in); `apply()` runs at activation or in direct store, inside the same DB transaction as user creation + attach_member + role, then the column is nulled.
- Member forms are built as the internal `final` `MemberForm` (extends Form) holding the set of fields SHOWN to the actor. Rules, getState and toData are limited to that set, so hidden fields are never validated, never accepted and never sent to the browser.
- "Shown" walks the schema tree: a field inside a hidden Section/Grid/Fieldset/Tabs counts as hidden (`MemberFieldsForm::visibleNames()`).
- On edit, the state is previous values minus shown fields, plus the submitted state (`merge()`), so `apply()` always gets the full state.
- `Form::strictOptions()` (@internal) + the `InOptions` rule reject option ids outside the schema's options. relationship() selects check by query: pluck the keys and compare them as strings.
- Lifecycle events are ShouldDispatchAfterCommit and are dispatched after the transaction returns. Revoke/activate re-read the row with lockForUpdate.

## Tried and ruled out
- Replacing the stored JSON with `getState()` on update: it erased fields hidden from the acting admin (portal access silently revoked).
- Checking only each field's own `isHidden()`: a hidden Section still leaked and accepted its children.
- Filling hidden keys as null placeholders: `fill()` puts the field default there, which leaks a misleading value. Omit the keys instead.
- `whereKey()->count()` for relationship options: it integer-casts, so '1abc' validated as id 1.

## Watch out
- The generic Form engine (resources, settings) still ignores hidden ancestor containers in getState/getValidationRules. Only membership is protected via MemberForm. This is an open follow-up.
- Form field ids can be prefixed via `KINETIX_FIELD_ID_PREFIX` / `useKinetixFieldId()`. Without a provider, ids stay exactly the field name. Use the prefix whenever two forms with the same field names can coexist.
- `values()` must return option ids as strings, or every save fires MemberUpdated.
