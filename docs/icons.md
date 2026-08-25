# Icons

Every icon in Kinetix is declared from PHP by **name** — `Action::make()->icon('trash-2')`, an onboarding step, a spotlight source, an `IconColumn` mapping, the `getIcon()` of an enum implementing `HasIcon`. The frontend resolves that name through one shared map, so the same name looks the same in a table row, a page header and a dropdown.

```php
Action::make('adjust')->label(__('inventory.adjust'))->icon('sliders-horizontal');
```

Names are kebab-case [Lucide](https://lucide.dev) names, matched case-insensitively.

---

## 1. An unknown name degrades — it never disappears

A name the map cannot resolve renders **no icon**, and everything else stays:

| Action | With a resolvable icon | With an unknown name |
|---|---|---|
| Normal button | icon + label | label only |
| `->iconButton()` | icon only, label as `aria-label`/tooltip | **label**, at normal button size |

That last row matters. An icon button hides its label, so an unresolvable icon used to paint a button with no icon *and* no label — present, focusable, clickable, and completely invisible. It now falls back to the label and gives up its icon-only sizing, because a control the user cannot see is worse than one that is slightly wider than intended.

::: tip It also tells you
In a development build, the first time a non-empty name fails to resolve, the console names it and how to register it. It warns once per name, so a table of 500 rows does not flood the console.
:::

---

## 2. Registering your own icons

Line-of-business apps declare icons Kinetix does not ship. Register them **once** from your app's entry point:

```ts
// resources/js/app.ts
import { Boxes, Forklift, RefreshCw } from '@lucide/vue';
import { registerIcons } from '@/composables/useKinetixIcons';

registerIcons({
    'refresh-cw': RefreshCw,
    forklift: Forklift,
    boxes: Boxes,
});
```

That is the whole API. Three things make `app.ts` the right home for it:

- **Kinetix never publishes `app.ts`**, so `kinetix:upgrade --force` cannot drop the registration. Patching `useKinetixIcons.ts` — the only option before this existed — had to be re-applied on every bump.
- **Icons are passed as components, not strings**, so your bundler still tree-shakes: you pay for the icons you register, not for all of Lucide. A `name => 'string'` config block resolved at runtime could not do that, which is why there is no `kinetix.icons` config key.
- **Any component works** — Lucide is the default, not a requirement. A registered name can be an icon from another set or a Vue component of your own.

A registration **overrides** a shipped name, so this is also the supported way to swap an icon without editing a published component:

```ts
registerIcons({ edit: MyPencil });
```

### Checking your names in a test

`registeredIconNames()` returns every resolvable name (shipped plus registered), sorted, so a host test can assert that everything the app declares actually resolves:

```ts
import { registeredIconNames, resolveIcon } from '@/composables/useKinetixIcons';
import '@/app-icons'; // wherever you call registerIcons()

it.each(ICON_NAMES_DECLARED_IN_PHP)('resolves %s', (name) => {
    expect(resolveIcon(name), `${name} is not registered`).toBeTruthy();
});
```

---

## 3. What ships

The shipped map covers the CRUD vocabulary the prebuilt actions use, dashboard/widget icons, and the ordinary line-of-business vocabulary that any commerce, org-chart or status UI reaches for on day one:

| Group | Names |
|---|---|
| CRUD & table | `edit` · `pencil` · `delete` · `trash` · `trash-2` · `view` · `eye` · `create` · `plus` · `download` · `upload` · `restore` · `rotate-ccw` · `refresh-cw` · `settings` · `filter` · `sliders-horizontal` · `copy` · `link` · `unlink` · `external-link` · `table` · `grid-3x3` · `archive` · `history` · `arrow-left-right` |
| Chrome | `more-vertical` · `ellipsis-vertical` · `more-horizontal` · `ellipsis-horizontal` · `chevron-down` · `arrow-up` · `arrow-down` · `check` · `x` · `circle` · `star` · `heart` · `sparkles` |
| Status | `check-circle` · `check-circle-2` · `x-circle` · `minus-circle` · `alert-circle` · `alert-triangle` · `info` · `help-circle` · `ban` · `shield` · `shield-check` · `lock-open` · `snowflake` |
| People & org | `user` · `users` · `user-check` · `building` · `building-2` · `briefcase` · `home` · `globe` · `crown` · `mail` · `phone` · `calendar` |
| Commerce | `store` · `printer` · `receipt` · `banknote` · `hand-coins` · `credit-card` · `wallet` · `dollar-sign` · `percent` · `shopping-cart` · `shopping-bag` · `shopping-basket` · `truck` · `package` · `box` · `cube` |
| Data & docs | `file-text` · `book` · `book-open` · `chart-bar` · `chart-column` · `bar-chart` · `trending-up` · `trending-down` · `arrow-up-right` · `arrow-down-right` · `activity` · `clock` |
| Trades & services | `wrench` · `webhook` · `stethoscope` · `pill` · `utensils-crossed` |

Anything outside this list is a `registerIcons()` call away — and if a name belongs in the shipped set (generic vocabulary, not app-specific), open an issue and it can move here.
