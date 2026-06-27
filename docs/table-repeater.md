# Table Repeater

A `Repeater` rendered as a **spreadsheet-style table**: each item is a row, each
sub-field a column. Click **Add** to append rows live, edit cells inline, with
footer **summaries** and **CSV export** like a table — perfect for line items,
inventories, settings grids, anything tabular.

Two save modes:

- **Deferred** *(default)* — rows live in the form state and are saved with the
  parent form submit. One write, no unnecessary saves.
- **Autosave** — bind a relation and every add/edit/delete persists to the DB
  immediately through a signed-descriptor endpoint.

<Screenshot name="table-repeater" alt="Editable table repeater" />

---

## Deferred (form field)

```php
use Happones\Kinetix\Forms\Components\TableRepeater;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\NumberField;

TableRepeater::make('items')
    ->columns([
        TextInput::make('name')->required(),
        NumberField::make('qty'),
        NumberField::make('price')->prefix('$'),
    ])
    ->summarize(['qty' => 'sum', 'price' => 'sum'])
    ->exportable()
    ->minItems(1)
    ->maxItems(50);
```

The field value is an array of rows (`[{ name, qty, price }, …]`), validated and
saved with the rest of the form. `columns()` is an alias of the Repeater's
`schema()` — any Kinetix form field works as a column (text, number, select,
toggle, date…).

`summarize()` adds a footer aggregate per column: `sum` · `avg` · `count` ·
`min` · `max`. `exportable()` shows a button that downloads the current rows as
CSV.

---

## Autosave (relation-backed)

```php
TableRepeater::make('items')
    ->relationship('items')   // a hasMany / hasOne relation on the form's record
    ->autosave()
    ->columns([
        TextInput::make('name'),
        NumberField::make('qty'),
    ]);
```

Each new row, cell edit (debounced) and deletion is written straight to the
related table. Kinetix mints a **signed descriptor** for the field (parent model
+ relation + the writable-column allowlist), so the autosave endpoint only ever
writes the declared columns on that relation — the same guard model as inline
table [cell editing](/tables). Cell edits are batched per row and flushed ~500 ms
after typing stops.

> Fill the field from the relation in your controller, e.g.
> `Form::make($record)->fill()` with an `items` accessor, or pass
> `$record->items` as the field value.

---

## Props (serialized)

| Method            | Effect |
| ----------------- | ------ |
| `columns([...])`  | The per-row fields (alias of `schema()`) |
| `summarize([...])`| Footer aggregate per column (`sum`/`avg`/`count`/`min`/`max`) |
| `exportable()`    | Show the CSV export button |
| `relationship()`  | Bind rows to an Eloquent relation (enables autosave) |
| `autosave()`      | Persist each change immediately (needs a relationship) |
| `minItems()` / `maxItems()` | Bound the row count |
| `addActionLabel()`| Custom "Add" button label |

---

## Endpoints (autosave)

Registered under the tables group (team-aware), guarded by the field descriptor:

| Method   | Route                          | Action |
| -------- | ------------------------------ | ------ |
| `POST`   | `{prefix}/tables/table-repeater` | Create a row |
| `PUT`    | `{prefix}/tables/table-repeater` | Update a row |
| `DELETE` | `{prefix}/tables/table-repeater` | Delete a row |

Each request carries the encrypted `token`; tampered or unsigned tokens are
rejected (`400`), and only allowlisted columns are written.
