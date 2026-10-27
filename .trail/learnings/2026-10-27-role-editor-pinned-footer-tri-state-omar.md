---
title: "Role editor: pinned footer, tri-state bulk selection, contentScroll modal"
author: Omar
date: 2026-10-27
branch: main
cost: { minutes: 14, tokens: 253720 }
touches:
  - ".agents/skills/kinetix-development/SKILL.md"
  - AGENTS.md
  - CHANGELOG.md
  - docs/forms.md
  - docs/permissions.md
  - docs/public/screenshots/role-matrix-editor-dark.png
  - docs/public/screenshots/role-matrix-editor.png
  - gallery/messages.en.json
  - gallery/specimens.ts
  - gallery/stubs/http.ts
---

## Decided
- The role editor (Roles/KinetixRoleEditorModal.vue) uses KinetixModal `content-scroll`: header + footer pinned, the body is a `min-h-0 flex flex-col`, and only the matrix (`min-h-0 flex-1 overflow-auto`) scrolls. Name field and bulk bar (filter, Select all, Clear all, count) stay fixed above it.
- The Cancel / Create (Save when editing) buttons live in the modal's `#footer` slot, outside the form; submit goes through `<KinetixButton type="submit" :form="formId">` with `useId()`. Native `required` validation still runs.
- Bulk selection is tri-state at four levels (module row, column header, group title, Select all) through one `bulkState(permissions)` → `{modelValue, indeterminate, disabled}` bound with v-bind, plus `setPermissions(list, granted)`. Select all and Clear all act on the filtered rows; the count always covers the whole role.
- `contentScroll` bounds the panel only under `[@media(min-height:40rem)]`. On a shorter viewport the bound squeezed the matrix to a sliver, so there the whole panel scrolls in the wrapper.

## Tried and ruled out
- `scroll-body` for this modal: it wraps the whole form (footer included) in a ScrollArea, so the buttons scrolled away and the matrix's own overflow never kicked in.

## Watch out
- KinetixCheckbox indeterminate was nearly invisible in dark mode: `dark:bg-input/30` beat `data-[state=indeterminate]:bg-primary`. It needs its own `dark:data-[state=indeterminate]:bg-primary`, like checked has.
- happy-dom proxies <form> elements: `form.contains(child)` and `closest('form') === form` fail even when the DOM is right. Compare ids.
- A failed assertion skips a test's own `wrapper.unmount()`, and the teleported dialog leaks into the next test's document queries. Use `enableAutoUnmount(afterEach)` and clear the body.
- The screenshot script's default viewport for open specimens is 460px tall, below the 40rem bound. The role-matrix-editor specimen sets `height: 760`.
