# Custom Pages

Not every screen is a resource. A dashboard, a wizard of your own, a POS terminal, a bulk-adjust form — the body is yours, but the chrome around it (a title, a "Back" action, a Save/Cancel bar) is the same everywhere, and it belongs on the server where authorization, routes and translations already live.

`Happones\Kinetix\Pages\Page` declares that chrome. It says **nothing** about the body.

```php
use App\Kinetix\Pages\InventoryAdjustPage;

return inertia('Kinetix/InventoryAdjust', [
    'page' => InventoryAdjustPage::make()->record($item)->toArray(),
    // …and whatever the body needs
]);
```

```vue
<KinetixPageShell :page="page">
    <MyOwnComponent />     <!-- anything at all -->
</KinetixPageShell>
```

::: tip This is a convenience, not a requirement
`Page` + `KinetixPageShell` is one way to get both action bars. Composing `<KinetixPageHeader>` and `<KinetixPageFooter>` by hand — passing `KinetixAction[]` props directly — stays fully supported and is the better fit when the page needs something between them that the shell's slots don't cover. See [Actions § Page Action Bars](actions.md).
:::

---

## 1. Scaffolding one

```bash
php artisan kinetix:make-page InventoryAdjust
```

Three files, none of which assumes anything about the content:

| File | What it is |
|---|---|
| `app/Kinetix/Pages/InventoryAdjustPage.php` | The page class — heading + both action bars |
| `app/Http/Controllers/Kinetix/InventoryAdjustController.php` | A single-action controller returning the Inertia page |
| `resources/js/pages/Kinetix/InventoryAdjust.vue` | The Vue page: the shell, and a placeholder body to replace |

| Option | Effect |
|---|---|
| `--sticky-footer` | Declare `$stickyFooter = true` on the generated class |
| `--no-controller` | Skip the controller |
| `--no-view` | Skip the Vue page |
| `--force` | Overwrite existing files |

The command prints the route line to register:

```php
use App\Http\Controllers\Kinetix\InventoryAdjustController;

Route::get('/inventory-adjust', InventoryAdjustController::class)->name('inventory-adjust');
```

`kinetix:make-page InventoryPage` and `kinetix:make-page Inventory` produce the same `InventoryPage` class — the suffix is normalized, never doubled.

---

## 2. The `Page` class

```php
namespace App\Kinetix\Pages;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Pages\Page;

class InventoryAdjustPage extends Page
{
    protected ?string $heading = 'Adjust stock';

    protected bool $stickyFooter = true;

    protected function buildHeaderActions(): array
    {
        return [
            Action::make('history')
                ->label(__('inventory.history'))->icon('history')->color('gray')
                ->url(fn ($record) => route('inventory.history', $record)),
        ];
    }

    protected function buildFooterActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('inventory.cancel'))->color('gray')
                ->url(route('inventory.index')),

            Action::make('post')
                ->label(__('inventory.post'))->icon('check')
                ->requiresConfirmation(__('inventory.post_confirm'))
                ->inertiaVisit(route('inventory.post'), ['method' => 'post']),
        ];
    }
}
```

Or inline, with no class at all:

```php
'page' => Page::make(__('inventory.adjust'))
    ->description(__('inventory.adjust_hint'))
    ->footerActions([$cancel, $post])
    ->stickyFooter()
    ->toArray(),
```

### API

| Method / property | Description |
|---|---|
| `::make(?string $heading = null)` | Create a page. On a subclass that declares `$heading`, the class wins — the argument does not override it |
| `->heading(?string)` | Set the title (explicit, so it always wins) |
| `->description(?string)` | Sub-heading text |
| `->headerActions(array)` / `protected buildHeaderActions()` | Actions in the header bar |
| `->footerActions(array)` / `protected buildFooterActions()` | Actions in the footer bar |
| `->record(?Model)` | The record the page is about — passed to every action, so `->url(fn ($record) => …)` and `->authorize()` receive it |
| `->stickyFooter(bool = true)` / `protected bool $stickyFooter` | Pin the footer bar |
| `->toArray()` / `->toData()` | Serialize for the Inertia prop (`Page` is `Arrayable` + `JsonSerializable`, so passing the object directly works too) |

::: warning Order footer actions primary-LAST
Below `sm` the footer row reverses, so the **last** action lands on top where the thumb is. Put Cancel first and Save last.
:::

### Authorization is enforced at serialization

An action the user may not run, or that hid itself, is dropped **before it reaches the browser** — `Action::toData()` returns null and the page filters it out:

```php
Action::make('post')->label(__('inventory.post'))
    ->authorize(fn () => auth()->user()->can('post', Adjustment::class)),
```

Nothing renders, and nothing about it is serialized either. Same rule as table actions.

---

## 3. `<KinetixPageShell>`

```vue
<script setup lang="ts">
import KinetixPageShell from '@/components/kinetix/KinetixPageShell.vue';
import type { KinetixPageData } from '@/types/kinetix';

defineProps<{ page: KinetixPageData }>();
</script>

<template>
    <KinetixPageShell :page="page">
        <template #header-before-actions>
            <MyFilterChips />
        </template>

        <MyOwnComponent />

        <template #footer-before-actions>Draft saved 2 minutes ago</template>
    </KinetixPageShell>
</template>
```

| Prop | Type | Description |
|---|---|---|
| `page` | `KinetixPageData` | The serialized page |
| `alwaysFooter` | `boolean?` | Render the footer bar even with no actions — for a footer whose content comes from a slot |

**Slots:** `default` (the body), plus `header-before-actions` / `header-actions` and `footer-before-actions` / `footer-actions`, forwarded to the corresponding bar.

A bar with nothing to show is not rendered, so a page that declares only a heading gets no empty footer.

---

## 4. What this is *not*

- **Not a router.** The page class declares chrome; you register the route and return the Inertia response yourself. Nothing is auto-discovered.
- **Not a form.** A page action carries no form of its own. To open one in a modal, give the action `->dispatch('some-event')` and host the modal on the page (pass `flat` to `<KinetixForm>` so its Sections don't render a card inside the panel).
- **Not `SettingsPage`.** [Settings](settings.md) pages are database-backed key/value forms with their own persistence. This is chrome around arbitrary content.
- **Not `Table::footerActions()`.** That renders inside a table's own footer card. This is the bottom of the **page**.
