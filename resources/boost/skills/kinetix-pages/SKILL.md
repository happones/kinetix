---
name: kinetix-pages
description: "Custom (non-resource) pages in Kinetix: the Happones\\Kinetix\\Pages\\Page class declaring a page's heading and its header/footer action bars, the <KinetixPageShell> component that renders them around arbitrary content, and the kinetix:make-page generator. Activates when building a screen that is not a CRUD resource — a dashboard, a POS terminal, a custom wizard, a bulk-action page — or when scaffolding a blank page with action bars."
license: MIT
metadata:
  author: happones
---

# Kinetix Custom Pages

## When to Apply

Activate this skill when:
- Building a screen that is **not** a CRUD resource: a dashboard, a terminal, a custom wizard, a bulk-adjust form.
- Declaring a page's heading and header/footer actions **from PHP** while the body stays your own Vue.
- Running `kinetix:make-page`, or wondering where a blank page scaffold lives.
- Choosing between `Page` + `KinetixPageShell` and composing `KinetixPageHeader`/`KinetixPageFooter` by hand.

## Documentation

For full details, reference `docs/pages.md` (published at https://happones.github.io/kinetix/pages).

## Scaffold

```bash
php artisan kinetix:make-page InventoryAdjust
# --sticky-footer  --no-controller  --no-view  --force
```

Writes `app/Kinetix/Pages/InventoryAdjustPage.php`, a single-action controller, and `resources/js/pages/Kinetix/InventoryAdjust.vue`, then prints the route line to register. The suffix is normalized: `InventoryAdjust` and `InventoryAdjustPage` both yield `InventoryAdjustPage`.

## The page class

```php
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Pages\Page;

class InventoryAdjustPage extends Page
{
    protected ?string $heading = 'Adjust stock';
    protected bool $stickyFooter = true;

    protected function buildHeaderActions(): array
    {
        return [
            Action::make('history')->label(__('inventory.history'))->icon('history')
                ->color('gray')->url(fn ($record) => route('inventory.history', $record)),
        ];
    }

    protected function buildFooterActions(): array
    {
        return [
            Action::make('cancel')->label(__('inventory.cancel'))->color('gray')
                ->url(route('inventory.index')),
            Action::make('post')->label(__('inventory.post'))->icon('check')
                ->requiresConfirmation(__('inventory.post_confirm'))
                ->inertiaVisit(route('inventory.post'), ['method' => 'post']),
        ];
    }
}
```

```php
return inertia('Kinetix/InventoryAdjust', [
    'page' => InventoryAdjustPage::make()->record($item)->toArray(),
]);
```

```vue
<KinetixPageShell :page="page">
    <MyOwnComponent />
</KinetixPageShell>
```

## Best Practices

- **The page declares CHROME only.** Heading, description, two action bars. It says nothing about the body on purpose — put a Kinetix table, a form, or your own components in the slot.
- **`->record($model)` whenever the page is about one.** Actions are serialized against it, so `->url(fn ($record) => …)` and `->authorize()` both receive it. Without it, a URL closure that requires a record is serialized as null.
- **Authorization happens at serialization.** An action the user may not run, or that hid itself, is dropped before it reaches the browser — never guard it in the Vue layer.
- **Order footer actions primary-LAST.** Below `sm` the footer row reverses so the last action lands on top under the thumb: Cancel first, Save last.
- **Wrap every label in `__()`.** These are the app's strings, not Kinetix's.
- **A page action carries no form.** To open one in a modal, use `->dispatch('event')` and host the modal on the page; pass `flat` to `<KinetixForm>` so Sections don't render a card inside the panel.
- **`Page` is optional sugar.** Composing `<KinetixPageHeader>` + `<KinetixPageFooter>` with plain `KinetixAction[]` props is equally valid and better when the layout between them is unusual.
- **Don't confuse it with two neighbours**: `SettingsPage` is a database-backed key/value form (see `kinetix-settings`); `Table::footerActions()` renders inside a table's own footer card, not at page level.
- **Nothing is auto-discovered.** You register the route and return the Inertia response; there is no page router.
