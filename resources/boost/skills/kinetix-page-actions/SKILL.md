---
name: kinetix-page-actions
description: "Page-level action bars in Kinetix — KinetixPageHeader (title + actions) and KinetixPageFooter (Save/Cancel/Archive, optionally sticky), both independent of the page's content, plus the shared KinetixActionBar. Activates when building a custom page with header or footer actions, adding a Save/Cancel bar to a hand-written Vue page, or putting page actions around a component that is not a Kinetix table or form."
license: MIT
metadata:
  author: happones
---

# Kinetix Page Action Bars

## When to Apply

Activate this skill when:
- Building a **custom page** (not a scaffolded resource page) that needs header and/or footer actions.
- Adding a Save / Cancel / Submit bar to a page whose body is your own Vue component.
- Wanting page actions **independent of the content** between them.
- Rendering an action row somewhere neither bar fits.
- Reaching for `Table::footerActions()` for a page footer — that is a **different** thing (see below).

## Documentation

For full details, reference `docs/actions.md` § "Page Action Bars" (published at https://happones.github.io/kinetix/actions).

## The shape

Neither bar knows anything about what the page renders between them:

```vue
<script setup lang="ts">
import KinetixPageHeader from '@/components/kinetix/KinetixPageHeader.vue';
import KinetixPageFooter from '@/components/kinetix/KinetixPageFooter.vue';
import type { KinetixAction } from '@/types/kinetix';

defineProps<{ headerActions: KinetixAction[]; footerActions: KinetixAction[] }>();
</script>

<template>
    <KinetixPageHeader heading="Inventory" :actions="headerActions" />

    <MyCustomThing />   <!-- anything at all -->

    <KinetixPageFooter :actions="footerActions" sticky>
        <template #before-actions>Last saved 2 minutes ago</template>
    </KinetixPageFooter>
</template>
```

Actions are ordinary `Action` objects, built in PHP and passed as props:

```php
return inertia('Inventory/Adjust', [
    'headerActions' => [
        Action::make('history')->label(__('inv.history'))->icon('history')->color('gray')
            ->url(route('inventory.history', $item)),
    ],
    'footerActions' => [
        Action::make('cancel')->label(__('inv.cancel'))->color('gray')
            ->url(route('inventory.index')),
        Action::make('post')->label(__('inv.post'))->icon('check')
            ->requiresConfirmation(__('inv.post_confirm'))
            ->inertiaVisit(route('inventory.post', $item), ['method' => 'post']),
    ],
]);
```

## Props

**`KinetixPageHeader`** — `heading?`, `description?`, `actions?`; slots `before-actions` + default.

**`KinetixPageFooter`** — `actions?`, `sticky?` (default `false`), `shortcuts?` (default `false`); slots `before-actions` + default.

**`KinetixActionBar`** — `actions?`, `shortcuts?` (default `true`), `stack?` (default `false`); slots `before` + `after`.

## Best Practices

- **`Table::footerActions()` is NOT a page footer.** It renders inside the table's own footer card. For actions at the end of a **page**, use `KinetixPageFooter`.
- **`sticky` is `position: sticky`, not `fixed`** — the bar stays part of the layout, so it never covers the last of your content and you don't owe it bottom padding. Use it for long pages where Save should stay reachable.
- **Leave `shortcuts` off in the footer** unless the action lives *only* there. A footer usually repeats what the header already bound, and two handlers on one chord is a bug.
- **Order the footer actions primary-last.** Below `sm` the row is `flex-col-reverse`, so the last action lands on top where the thumb is — put Cancel first, Save last.
- **Compose `KinetixActionBar`, never copy its template.** It is the single implementation of dropdown groups, the confirmation modal, `->shortcut()` registration and per-button pending state. A new action surface that re-implements the row will drift from the rest of the toolkit.
- **The label carries the accessible name.** Always set `->label()`, and pair `->iconButton()` with `->icon()` — an unresolvable icon falls back to the label (see the `kinetix-icons` skill).
- **A page action carries no form of its own.** To open a modal form from a header/footer action, use `->dispatch('some-event')` and host the modal on the page; pass `flat` to `<KinetixForm>` so its Sections don't render a card inside the panel.
