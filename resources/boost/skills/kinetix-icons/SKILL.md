---
name: kinetix-icons
description: "Icon names declared from PHP (Action::make()->icon('…'), IconColumn::options(), onboarding steps, spotlight sources, HasIcon enums) and resolved by one shared frontend map. Activates when an icon does not appear, when a button renders empty, when adding an icon Kinetix does not ship, or when registering/overriding icons with registerIcons()."
license: MIT
metadata:
  author: happones
---

# Kinetix Icons

## When to Apply

Activate this skill when:
- Setting an icon from PHP: `->icon('…')`, `IconColumn::options()/trueIcon()`, an onboarding step, a spotlight source, or the `getIcon()` of an enum implementing `HasIcon`.
- An icon does not show up, or a button renders **empty** / unexpectedly wide.
- The app needs an icon name Kinetix does not ship, or wants to replace one.
- Considering a patch to `resources/js/composables/useKinetixIcons.ts` — don't; use `registerIcons()`.

## Documentation

For full details, reference `docs/icons.md` (published at https://happones.github.io/kinetix/icons).

## Declaring icons

Kebab-case [Lucide](https://lucide.dev) names, matched case-insensitively, resolved by one shared map so the same name looks the same everywhere:

```php
Action::make('adjust')->label(__('inventory.adjust'))->icon('sliders-horizontal');

IconColumn::make('status')->options([
    'check-circle-2' => fn ($state) => $state === 'paid',
    'ban'            => fn ($state) => $state === 'void',
]);
```

## Registering icons the package does not ship

Call it **once** from the host's entry point:

```ts
// resources/js/app.ts
import { Boxes, Forklift } from '@lucide/vue';
import { registerIcons } from '@/composables/useKinetixIcons';

registerIcons({ forklift: Forklift, boxes: Boxes });
```

- `app.ts` is a file Kinetix **never publishes**, so `kinetix:upgrade --force` cannot drop the registration — unlike a patch to `useKinetixIcons.ts`, which had to be re-applied on every bump.
- Icons are **components, not strings**, so the bundler still tree-shakes: you pay for what you register. This is why there is deliberately **no `kinetix.icons` config block** — a name→string map resolved at runtime would pull all of Lucide into the bundle.
- Any Vue component works; Lucide is the default, not a requirement.
- A registration **overrides** a shipped name, so it is also how you swap an icon without editing a published component.
- `registeredIconNames()` lists every resolvable name (shipped + registered), sorted — useful for a host test that asserts every name the app declares resolves.

## Best Practices

- **An unknown name degrades, it does not vanish.** No icon renders; everything else stays. On an `->iconButton()` the **label** takes the icon's place and the button gives up its icon-only sizing — because an icon button hides its label, so an unresolvable icon used to paint a control with no pixels at all. Never assume an icon appeared just because you set one.
- **Watch the dev console.** The first time a non-empty name fails to resolve, it is logged with the fix (once per name, so a 500-row table does not flood it). That console line is the fastest answer to "why is this button blank?".
- **Always pair `->iconButton()` with `->icon()`** and keep `->label()` set: the label is the `aria-label`/tooltip, and now also the visible fallback.
- **Don't hand-roll icon rendering in a component.** Resolve through `resolveIcon()` and guard on the RESOLVED component (`v-if="resolveIcon(x)"`), never on the name; for action buttons use `isIconOnlyAction(action)` rather than `action.isIconButton`.
- **Generic vocabulary belongs upstream.** If a missing name is ordinary product vocabulary (commerce, org, status) rather than app-specific, it should ship in Kinetix — open an issue instead of carrying a permanent registration.
