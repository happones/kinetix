---
name: kinetix-table-repeater
description: "A Repeater rendered as an editable table — rows you add/edit/delete live, with summaries and CSV export. Deferred (saved with the form) by default; opt-in per-row autosave to a relation. Activates when building line-items / editable grids / inventory tables in a form."
license: MIT
metadata:
  author: happones
---

# Kinetix Table Repeater

## When to Apply

Activate this skill when:
- Building an editable table of rows inside a form (line items, inventory,
  settings grid) where users click "Add" to append rows and edit cells inline.
- You want footer summaries / CSV export on a repeater.

## Documentation

For full details, reference `docs/table-repeater.md` (published at https://happones.github.io/kinetix/table-repeater).

## Deferred (default — saved with the form)

```php
use Happones\Kinetix\Forms\Components\{TableRepeater, TextInput, NumberField};

TableRepeater::make('items')
    ->columns([
        TextInput::make('name')->required(),
        NumberField::make('qty'),
        NumberField::make('price'),
    ])
    ->summarize(['qty' => 'sum', 'price' => 'sum'])
    ->exportable();
```

Value is an array of rows, validated/saved with the parent form. `columns()` is
an alias of `schema()`; any field type works as a column. `summarize()` →
`sum`/`avg`/`count`/`min`/`max` footer per column.

## Autosave (per-row, to a relation)

```php
TableRepeater::make('items')
    ->relationship('items')   // hasMany/hasOne on the form record
    ->autosave()
    ->columns([TextInput::make('name'), NumberField::make('qty')]);
```

Each add/edit (debounced)/delete persists immediately. A signed descriptor
(parent + relation + writable-column allowlist) guards the endpoint, so only the
declared columns are written on that relation.

## Frontend

`<KinetixTableRepeater>` is rendered automatically by `KinetixForm` for the
`table-repeater` field type. Cells reuse `KinetixFormSchema`, so all field types
render. `useKinetixTableRepeater()` → `{ create, update, remove }` backs autosave.
i18n: `add_item`, `remove`, `export`, `table_repeater_empty`.
