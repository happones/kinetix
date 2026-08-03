# Kinetix Tables

Kinetix provides a powerful, Eloquent-driven, and highly interactive Tables system. Using a fluent PHP builder API, you configure columns, filters, and actions, serialize the configuration and data to JSON, and render a responsive, features-rich grid using Vue 3, Inertia.js 3, and TypeScript.

---

## Key Features

- **Fluent Schema Definitions**: Define columns, filters, and row-level actions with chaining methods.
- **Eager Loaded Relationships**: Safe dot-notation (e.g. `author.name`) displays relationship values without N+1 queries — Kinetix derives the `with()` from the columns you declared, so it needs no configuration and can't drift from what is rendered.
- **Dynamic Inline Editing**: Editable columns (Selects, Toggles, Text Inputs, Checkboxes) automatically perform background XHR updates to the database.
- **Security Signatures**: Eloquent model namespaces are securely encrypted on serialization, preventing client-side database class tampering.
- **Client-Side Column visibility**: Built-in column toggling lets users hide and show columns locally in the browser.
- **Interactive Headers**: Header triggers automate debounced searching, column visibility, active filters, and sorting.
- **Stat cards**: Optional KPI cards above the table (counts, sums, averages, with conditions) that fold into a single extra query no matter how many you declare. See [Stat cards](#stat-cards-stats).

---

## Basic Usage

### 1. Build the Table on the Backend

In your controller, define the `Table` query, register the columns/filters/actions, and pass the configuration to your Inertia page:

```php
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\IconColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Actions\Action;
use App\Models\Post;

public function index()
{
    // `with('author')` is optional — the table eager-loads the relations behind
    // its dot-notation columns automatically. Pass your own for anything else.
    $table = Table::make(Post::query())
        ->heading('Blog Posts')
        ->description('Manage your application articles.')
        ->striped()
        ->stickyActions() // optional: pin the actions column while scrolling horizontally
        ->columns([
            TextColumn::make('title')
                ->searchable()
                ->sortable(),

            TextColumn::make('author.name')
                ->label('Author'),

            TextColumn::make('status')
                ->badge()
                ->badgeColor(fn ($state) => match ($state) {
                    'published' => 'success',
                    'draft' => 'gray',
                    default => 'warning',
                }),

            ToggleColumn::make('is_featured')
                ->label('Featured'),
        ])
        ->filters([
            SelectFilter::make('status')
                ->options([
                    'draft' => 'Draft',
                    'published' => 'Published',
                ]),
        ])
        ->recordActions([
            Action::make('edit')
                ->icon('edit')
                ->url(fn ($record) => route('posts.edit', $record)),
        ]);

    return inertia('Posts/Index', [
        'postsTable' => $table->toArray(),
    ]);
}
```

### 2. Render the Table in Vue 3

Import `KinetixTable` and bind the serialized table prop:

```vue
<script setup lang="ts">
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import type { KinetixTableData } from '@/types/kinetix';

defineProps<{
    postsTable: KinetixTableData;
}>();
</script>

<template>
    <div class="py-12 max-w-7xl mx-auto px-4">
        <KinetixTable :table="postsTable" />
    </div>
</template>
```

::: warning Wide tables & `min-w-0`
`KinetixTable` scrolls a too-wide table **inside its own card** (an
`overflow-x-auto` container), and the card carries `min-w-0 max-w-full` so it can
shrink to the available width. But a **flex** ancestor won't let it: a flex item
defaults to `min-width: auto` and refuses to shrink below its content, so a wide
table makes the whole column grow and the page overflows the viewport instead of
scrolling locally.

The fix is `min-w-0` on the flex chain between your layout and the table. The
scaffolded page already applies it (`flex … min-w-0 …`); if you render
`<KinetixTable>` inside your own layout, add `min-w-0` to the flex content
column (a common starter-kit gotcha where the `<main>`/content flex item is
missing it):

```vue
<div class="flex min-w-0 flex-1 flex-col">
    <KinetixTable :table="postsTable" />
</div>
```

**Laravel starter kit?** Its `SidebarInset.vue` / `AppContent.vue` content wrappers
ship without `min-w-0` — see the exact one-line patch for each in
[Starter kit → Wide tables & the `min-w-0` layout fix](/starter-kit#wide-tables-the-min-w-0-layout-fix).
:::

---

## Rendering Modes: Server-driven vs Client-side

By default Kinetix Tables are **server-driven**: search, sort, filtering and pagination run at the database level, and each interaction is an Inertia partial reload. This scales to large datasets and keeps state in the URL (shareable/back-button friendly).

For **small, fully-loadable datasets**, opt into **client-side mode** with `->clientSide()`. The full (capped) result set is shipped once and a **TanStack Table** engine handles search / sort / pagination entirely in the browser — no round-trip per interaction:

```php
Table::make(Team::query())
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('members_count')->sortable(),
    ])
    ->clientSide();          // ship all rows; browser does the rest

Table::make(Country::query())->clientSide(max: 300);   // lower the safety cap (default 500)
```

The same PHP `Table` API drives both modes — you only add `->clientSide()`. The frontend `<KinetixTable>` component is unchanged; it lazy-loads the TanStack-backed renderer only when a table is client-side, so the dependency is **code-split** off the server-driven path.

::: tip Install the optional peer
Client-side mode needs `@tanstack/vue-table` in your app (`npm install @tanstack/vue-table`, or `php artisan kinetix:install --tanstack`). It's an **optional** peer dependency — server-driven tables never load it.
:::

**When to use which:**

| | Server-driven (default) | Client-side (`->clientSide()`) |
|---|---|---|
| Dataset size | Any (DB-paginated) | Small (≤ the cap, default 500 rows) |
| Search / sort / paginate | Database, per interaction | In-browser, instant |
| Interactive filters, saved views, polling, reorder, bulk actions | ✅ | — (use server mode) |
| Sort accuracy | Exact (DB-level) | On the **displayed** value (formatted); for exact numeric/date ordering keep server mode |

Client-side mode covers search, sort, pagination, column visibility, row actions and row clicks. For heavy interactive filtering or very large data, stay server-driven.

---

## Column Builders Reference

All column classes inherit from `Column` and reside in the `Happones\Kinetix\Tables\Columns` namespace.

### Shared Column Methods
- `make(string $name)`: Instantiates a column. The name can use dot-notation for relationship fields (e.g. `user.profile.phone`).
- `label(string $label)`: Customizes the display label in the header. Wrap it in `__()` to keep it translatable — `->label(__('posts.fields.title'))` (see [Localizing labels](/locale#translating-labels-you-declare-in-php-schemas)). Omit it and Kinetix auto-humanizes the column name.
- `searchable(bool $condition = true)`: Enables search queries against this column.
- `sortable(bool $condition = true, ?Closure $using = null)`: Enables active header sorting. The sort key is **allowlisted** against the defined sortable columns, so an arbitrary query-string value can never reach the query. Three modes:
  - Plain column (`TextColumn::make('name')->sortable()`) → `ORDER BY name`.
  - **Relationship column** (`TextColumn::make('author.name')->sortable()`) → sorts by the related column via a correlated subquery (no join, no row duplication). Supports `BelongsTo` and `HasOne`.
  - **Custom resolver** for anything else (multi-column, computed, `HasMany` aggregates):
    ```php
    TextColumn::make('full_name')->sortable(
        using: fn (Builder $query, string $direction) => $query
            ->orderBy('last_name', $direction)
            ->orderBy('first_name', $direction),
    );
    ```
- `alignment(string $alignment)`: Sets horizontal alignment (`left`, `center`, `right`).
- `toggleable(bool $isToggleable = true, bool $isToggledHiddenByDefault = false)`: Allows users to hide/show the column.
- `copyable(bool $condition = true)`: Shows a click-to-copy button on the cell (on hover) that copies its value to the clipboard. Works on any column type.
- `formatStateUsing(Closure $callback)`: Formats the value dynamically on the backend before serialization.
- `state(Closure|mixed $state)`: Overrides how the raw cell value is resolved — a Closure (`fn ($record) => …`) or a constant — instead of reading the attribute named after the column. `formatStateUsing()` still runs afterwards. Filament-compatible (alias: `getStateUsing()`):
  ```php
  TextColumn::make('total')->state(fn (Order $o) => $o->subtotal + $o->tax);
  ```

### 1. `TextColumn`
Displays text strings with additional formatting structures:
- `badge()`: Wraps the value in a rounded badge.
- `badgeColor(string|Closure $color)`: Sets status colors (`success`, `danger`, `warning`, `info`, `gray`).
- `date(?string $format = null)`: Formats carbon/datetime values. **With no argument the output is localized** to the application locale via Carbon `isoFormat()`, using the token from `config('kinetix.formats.date')` (default `ll` — "Jul 9, 2026" in `en`, "9 de jul. de 2026" in `es`). Passing a format keeps the plain, non-localized PHP `format()` behaviour (`->date('d/m/Y')`).
- `dateTime(?string $format = null)`: Same semantics, defaulting to `config('kinetix.formats.datetime')` (default `lll`, includes the time).
- `isoDate(?string $format = null)` / `isoDateTime(?string $format = null)`: Format with explicit Carbon isoFormat tokens, localized (`->isoDate('LL')` → "9 de julio de 2026" in `es`). Filament-compatible.
- `locale(string $locale)`: Override the formatting locale for this column (defaults to `app()->getLocale()`).
- `money(string $currency = 'USD', int $divideBy = 1, ?string $locale = null)`: Formats as **localized currency** via intl (`$1,234.50` in `en`, `1.234,50 €` in `de`). `$divideBy` converts minor units (`100` for cents); the locale resolves from the argument, then the column `->locale()`, then the app locale. Filament-compatible.
- `limit(int $limit)`: Truncates text.
- `description(string|Closure $description, string $position = 'below')`: Displays secondary description lines.

### 2. `IconColumn`
Displays an icon based on value states:
- `boolean()`: Helper to automatically show checkmark circles for `true` and cross circles for `false`.
- `options(array $options)`: Maps icon names to conditional statements or values.
- `colors(array $colors)`: Maps color labels to statements or values.

### 3. `ImageColumn`
Displays image thumbnail previews:
- `circular()`: Renders image as a circle.
- `square()`: Renders image as rounded square (default).
- `size(int|string $size)`: Dimensions in pixels (defaults to 40px).
- `defaultImageUrl(string|Closure $url)`: Fallback URL if state is null.
- `disk(string $disk)`: Resolve stored paths through a specific disk (e.g. `'s3'`). Defaults to the global `kinetix.filesystem.disk` (`public`). Stored paths become public URLs via `Storage::disk($disk)->url()`; values that are already absolute (`http(s)://`, `//`, `/…`, `data:`) pass through untouched.
- `preview()`: Makes the thumbnail clickable to open a zoomable lightbox. Requires `<KinetixFilePreview />` mounted once in the layout (see [Actions → File actions](actions.md)).

### 4. `ColorColumn`
Displays visual color swatch blocks:
- `copyable()`: Allows users to click on the color swatch to copy the hex code to their clipboard.

### 5. `SelectColumn` (Editable)
Renders a dropdown selector in the cell:
- `options(array|Closure $options)`: Array of key-value option values.

### 6. `ToggleColumn` (Editable)
Renders a switch button inside the cell to edit boolean properties instantly.

### 7. `TextInputColumn` (Editable)
Renders an inline text box:
- `type(string $type)`: Input field type (text, number, email, date).
- `placeholder(string $placeholder)`: Default placeholder.

### 8. `CheckboxColumn` (Editable)
Renders a standard checkbox toggle inside the cell.

### 9. `NumberInputColumn` (Editable)
Renders an inline numeric input with increment/decrement steppers (Reka UI
NumberField). Supports `min()` / `max()` / `step()`, `decimals()`, and the
`percent()` / `currency()` formats — mirroring the [`NumberField`](/forms#numberfield)
form field. Edits save through the same cell-update endpoint.

```php
use Happones\Kinetix\Tables\Columns\NumberInputColumn;

NumberInputColumn::make('stock')->min(0)->step(1);
NumberInputColumn::make('price')->currency('USD');
```

### 10. `ProgressColumn`
Displays numeric/quantity values with a supporting progress bar. Very useful for stock tracking, capacities, goals, etc.:
- `progress(int|float|string|Closure $progress)`: Defines the progress percentage (0 to 100). Can be a number, a string representing another column name, or a closure.
- `maxValue(int|float|string|Closure $maxValue)`: Dynamically computes percentage if no explicit progress is specified (`($value / $maxValue) * 100`).
- `color(string|Closure $color)`: Status color for the text and bar (`success`, `danger`, `warning`, `info`, `primary`, `gray`).

```php
use Happones\Kinetix\Tables\Columns\ProgressColumn;

// Stock tracking with warning threshold
ProgressColumn::make('quantity')
    ->maxValue(fn ($record) => $record->min_stock * 5)
    ->color(fn ($value, $record) => $value < $record->min_stock ? 'danger' : 'success');
```

### 11. `ViewColumn`
Renders a column using a custom Vue component registered in your host application:
- `view(string $componentName)`: Set the name of the globally or locally registered Vue component.
- `props(array|Closure $props)`: Key-value pairs or a closure that returns props to pass to the Vue component for the row.

```php
use Happones\Kinetix\Tables\Columns\ViewColumn;

ViewColumn::make('avatar')
    ->view('UserAvatarCell')
    ->props(fn ($record) => [
        'size' => 48,
        'borderColor' => $record->active ? 'green' : 'gray',
    ]);
```

Custom Vue components receive the following default props:
*   `record`: The full `KinetixTableRecord` object representing the row.
*   `value`: The resolved state/value of the column for the row.
*   Any extra props evaluated from `props(...)` are bound via `v-bind`.

---

## Custom Cell Slots (Frontend Overrides)
If you are rendering the `<KinetixTable>` component on a custom page and need page-level ad-hoc overrides, you can use Vue's scoped slots.
KinetixTable dynamically generates slots for each column in the format `cell-{column_name}`:

```vue
<KinetixTable :table="table">
    <template #cell-status="{ record, value }">
        <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
            {{ value }} (ID: {{ record.id }})
        </span>
    </template>
</KinetixTable>
```

---

## Table Filters Reference

All filters reside in the `Happones\Kinetix\Tables\Filters` namespace.

### 1. `Filter`
Renders as a checkbox.
- `label(string $label)`: Custom display label (defaults to the TitleCase headline of the filter name). Available on all filters.
- `query(Closure $callback)`: Closure modifying the query builder: `fn (Builder $query, $value) => $query->where(...)`.
- `default(mixed $value)`: Default active state.

### 2. `SelectFilter`
Renders as a dropdown select.
- `options(array|Closure|string $options)`: Dropdown option pairs, a closure, or an Enum class (auto-mapped to value→label).
- `attribute(string $attribute)`: Maps query parameters directly to database columns. If omitted, defaults to the filter name.
- `relationship(string $name, string $titleColumn, ?Closure $modifyQueryUsing = null)`: Filament-compatible — options come from the related model (`key => title column`) and the filter applies `whereHas` on the relation's key. Inherited by `MultiSelectFilter`, and mirrored by [`Select::relationship()`](/forms#from-a-relationship) on the form side. The eager options are capped by `kinetix.forms.relationship_options_limit` (200), with a warning when truncated:
  ```php
  SelectFilter::make('author')->relationship('author', 'name');
  SelectFilter::make('author')->relationship('author', 'name', fn ($q) => $q->where('active', true));
  ```
  For large related tables prefer `searchUsing()` (options load lazily instead of all at once).
- `searchable()`: Renders the select dropdown as a searchable combobox.
- `searchUsing(string $model, string $labelColumn = 'name', array $searchColumns = ['name'], string $valueColumn = 'id')`: Dynamically queries the model's database table when searching. The selection is securely query-guarded via tokens.

### 3. `MultiSelectFilter`
Renders as a checkbox list; matches any selected value via `whereIn`. Extends `SelectFilter` (supports all the same options, including Enum classes, `relationship()`, `searchable()`, and `searchUsing()`). When searchable or remote-searching, a search input is rendered at the top of the checkbox list to query options.

```php
use Happones\Kinetix\Tables\Filters\MultiSelectFilter;

MultiSelectFilter::make('status')->options(PostStatus::class);

// Remote search multi-select filter
MultiSelectFilter::make('user_ids')
    ->searchUsing(User::class, 'name', ['name', 'email']);
```

### 4. `TernaryFilter`
A tri-state dropdown (All / true / false) for boolean columns.

```php
use Happones\Kinetix\Tables\Filters\TernaryFilter;

TernaryFilter::make('is_active')
    ->trueLabel('Active')
    ->falseLabel('Inactive');

// Custom queries per branch (e.g. for nullable columns):
TernaryFilter::make('email_verified')
    ->queries(
        true: fn ($q) => $q->whereNotNull('email_verified_at'),
        false: fn ($q) => $q->whereNull('email_verified_at'),
    );
```

| Method | Description |
|---|---|
| `attribute(string)` | Column to filter (defaults to the filter name) |
| `trueLabel(string)` / `falseLabel(string)` | Option labels (default `Yes` / `No`) |
| `queries(Closure $true, Closure $false)` | Custom query per selection |

> **Date filters use the shadcn calendar by default.** `DateFilter`, `DateTimeFilter` and `DateRangeFilter` all render a Reka UI calendar popover out of the box. Call **`->native()`** on any of them to fall back to plain native `<input>` controls. They require `reka-ui` and `@internationalized/date` in the host app (already present for shadcn-vue users; declared as optional peer dependencies).

### 5. `DateRangeFilter`
A from / to range; filters `whereDate >= from` and `<= to`. Either bound is optional. Renders `KinetixRangeCalendar.vue` (Reka UI `RangeCalendar`) by default.

```php
use Happones\Kinetix\Tables\Filters\DateRangeFilter;

DateRangeFilter::make('created_at');                       // shadcn range calendar
DateRangeFilter::make('published')->attribute('published_at');

DateRangeFilter::make('created_at')->native();             // native from/to inputs
DateRangeFilter::make('created_at')->months(2)->locale('es');
```

| Method | Description |
|---|---|
| `->native(bool = true)` | Use two native date inputs instead of the calendar |
| `->calendar(bool = true)` | Explicitly use the calendar variant (the default) |
| `->months(int)` | Number of month grids shown side by side |
| `->locale(string)` | BCP-47 locale for weekday/month names, e.g. `'es'`, `'fr'` — **defaults to the application locale** (`app()->getLocale()`, `es_MX` → `es-MX`) |
| `->weekdayFormat(string)` | Weekday header labels: `'narrow'` (default), `'short'`, `'long'` |
| `->fixedWeeks(bool = true)` | Always render 6 week rows for a constant calendar height |
| `->minValue(string)` | Earliest selectable date (`'Y-m-d'`); earlier dates are disabled |
| `->maxValue(string)` | Latest selectable date (`'Y-m-d'`); later dates are disabled |

### 6. `NumberRangeFilter`
Two number inputs (min / max); filters `>= min` and `<= max`. Either bound is optional.

```php
use Happones\Kinetix\Tables\Filters\NumberRangeFilter;

NumberRangeFilter::make('price');
```

### 7. `DateFilter`
A single date. Defaults to matching that exact day (`whereDate =`); configurable. Renders `KinetixDatePicker` (shadcn calendar) by default; `->native()` for a native input. `->locale()`, `->minValue()`, `->maxValue()` configure the calendar.

```php
use Happones\Kinetix\Tables\Filters\DateFilter;

DateFilter::make('published_at');                  // shadcn calendar
DateFilter::make('published_at')->operator('>=');  // on or after
DateFilter::make('published_at')->native();        // native date input
```

### 8. `DateTimeFilter`
A single date + time. Defaults to "on or after" (`>=`); configurable via `operator()`. Renders `KinetixDateTimePicker` (calendar + scrollable hour/minute columns) by default; `->native()` for a native `datetime-local`. `->minuteStep()` sets the minute granularity and `->twelveHour()` adds an AM/PM column.

```php
use Happones\Kinetix\Tables\Filters\DateTimeFilter;

DateTimeFilter::make('starts_at')->minuteStep(15);
DateTimeFilter::make('ends_at')->operator('<=')->twelveHour();
DateTimeFilter::make('ends_at')->native();
```

### `MonthFilter`, `YearFilter` & `WeekFilter`
Filter a date column by month, year, or ISO week. Each renders the matching
shadcn picker by default (`->native()` for the browser-native input) and accepts
`->attribute()`, `->minValue()`, `->maxValue()` (and `->locale()` for month/week).

```php
use Happones\Kinetix\Tables\Filters\{MonthFilter, YearFilter, WeekFilter};

MonthFilter::make('created_at');                       // value "2026-06"
YearFilter::make('created_at')->minValue('2020');      // value "2026"
WeekFilter::make('created_at')->native();              // value "2026-W25"
```

- `MonthFilter` → `whereYear` + `whereMonth`.
- `YearFilter` → `whereYear`.
- `WeekFilter` → matches rows whose date falls in that ISO week (Mon–Sun).

### `AddressFilter`
Free-text address search. Renders a single text input and matches the term with
**OR `LIKE`** across the columns you pass to `->columns()` (defaults to the filter
name). Pair it with the [`AddressPicker`](/forms#addresspicker) form field.

```php
use Happones\Kinetix\Tables\Filters\AddressFilter;

AddressFilter::make('address')->columns(['city', 'state', 'postal_code', 'country']);
```

### 9. `TrashedFilter`
Soft-delete scope filter for `SoftDeletes` models. Blank = active records only (default), *With deleted* = `withTrashed()`, *Only deleted* = `onlyTrashed()`.

```php
use Happones\Kinetix\Tables\Filters\TrashedFilter;

Table::make(User::query())->filters([TrashedFilter::make()]);
```

Pair it with `RestoreAction` / `ForceDeleteAction` record actions (see [Actions](actions.md#8-prebuilt-crud-actions)), which only appear on trashed rows.

> Range and multi-select filters submit structured values (`{from,to}`, `{min,max}`, or an array). Each table namespaces its own filter query-string params (see `queryPrefix`), so multiple filtered tables coexist on one page.

---

## In-Table Record Actions

Add row-level action buttons at the end of each table line:

```php
$table->recordActions([
    Action::make('edit')->icon('edit')->url(fn ($record) => "/posts/{$record->id}/edit"),
    Action::make('delete')->icon('delete')->color('danger')->dispatch('delete-record'),
]);
```

### Header / toolbar & footer actions

Table-level actions (not tied to a row) live in two slots:

```php
// Top toolbar (next to search/filters). `headerActions()` is an alias.
$table->toolbarActions([
    Action::make('create')->label('New post')->icon('plus')->url('/posts/create'),
    Action::make('import')->label('Import')->icon('upload')->dispatch('open-importer'),
    Action::make('export')->label('Export')->icon('download')
        ->inertiaVisit(route('posts.export')),
]);

// A bar below the table, next to pagination (e.g. "Export all").
$table->footerActions([
    Action::make('export-all')->label('Export all')->icon('download')
        ->inertiaVisit(route('posts.export')),
]);
```

**Where do Import / Export go?** As **header/toolbar actions** (they act on the whole, filtered table). Export = an action that hits your export route (which dispatches `ExportProcessor`). Import = an action that opens your `<KinetixImporter>` (e.g. `->dispatch('open-importer')` toggling its visibility), since the importer is a standalone preview component.

**Supporting both whole-table and selected-rows:** place the *same* `Action` in `toolbarActions`/`footerActions` **and** in `bulkActions`. The toolbar/footer copy runs against the whole table; the bulk copy automatically receives the selected `ids` (see below). One Export action → "export all" + "export selected".

---

## Bulk Actions

`bulkActions([ ... ])` enables row selection. A select-all + per-row checkbox column appears, and when rows are selected a bulk action bar shows the action buttons plus a selected count and a clear button.

```php
$table->bulkActions([
    Action::make('delete')->label('Delete selected')->icon('trash')->color('danger')
        ->requiresConfirmation('Delete the selected records?')
        ->inertiaVisit(route('posts.bulk-destroy'), ['method' => 'delete']),

    Action::make('export')->label('Export')->icon('download')
        ->dispatch('export-selected'),
]);
```

The selected record ids are sent automatically:
- **`inertiaVisit`** actions receive them as `ids` in the request payload (`$request->input('ids')`).
- **`dispatch`** actions receive them in the event detail: `e.detail.ids`.

Destructive bulk actions support `requiresConfirmation()` (a confirmation modal gates them), and they respect `authorize()` / `visible()` like any action — e.g. `->authorize('deleteAny', Post::class)`.

**Exporting selected rows:** see the full recipe — one Export action shared between the toolbar (export all) and bulk (export selected `ids`) — in [Import / Export → Recipe: export from a table](import-export.md#recipe-export-from-a-table--toolbar-all--bulk-selected).

---

## Summaries

Render a summary row in the table footer with the results of calculations
(sum, average, count, range) over the **full filtered dataset**. Add one or more
**summarizers** to a column with `summarize()`:

<Screenshot name="table-summaries" alt="Table with a summary footer" />

```php
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\Summarizers\Average;
use Happones\Kinetix\Tables\Columns\Summarizers\Range;
use Happones\Kinetix\Tables\Columns\Summarizers\Sum;

TextColumn::make('price')->summarize(Sum::make()->money('EUR'));

TextColumn::make('rating')->summarize([
    Average::make()->label('Avg')->numeric(decimalPlaces: 1),
    Range::make(),
]);
```

### Available summarizers

| Summarizer  | Result |
| ----------- | ------ |
| `Sum`       | Total of all values |
| `Average`   | Mean of all values |
| `Count`     | Number of rows (scope with `->query()`) |
| `Range`     | `min – max` (`->minimalTextualDifference()`, `->minimalDateTimeDifference()`, `->excludeNull(false)`, `->limit(n)`) |
| `Summarizer`| Custom — `Summarizer::make()->using(fn (Builder $q) => $q->min('last_name'))` |

### Shared methods

`label()`, `query(fn ($q) => …)` (scope the dataset), `prefix()` / `suffix()`,
`numeric(decimalPlaces, locale)`, `money(currency, divideBy, locale)`, and
`hidden()` / `visible()` (each receives the query builder). Set a global number
locale with `config('kinetix.tables.number_locale')`.

```php
// Count only published rows:
IconColumn::make('is_published')
    ->summarize(Count::make()->query(fn ($q) => $q->where('is_published', true)));

// Prices stored in cents:
TextColumn::make('price')->summarize(Sum::make()->money('USD', divideBy: 100));
```

### One scan, not one per summarizer

Every plain aggregate is folded into a **single** query, across all columns:

```php
Table::make(Order::query())->columns([
    TextColumn::make('amount')->summarize([Sum::make(), Average::make(), Range::make()]),
    TextColumn::make('id')->summarize(Count::make()),
]);
```

```sql
-- one scan of the filtered set, not five
select sum("amount"), avg("amount"), min("amount"), max("amount"), count(*) from "orders" where …
```

Two things opt a summarizer **out** of the shared query, because both change the
dataset it measures:

- `->query(fn ($q) => …)` — a narrower scope;
- `->using(fn ($q) => …)` — a fully custom value.

Those keep their own query, so the cost is `1 + (however many of those you
declared)`. Everything else is free once the first scan is paid for.

### Including summaries in exports

`ExportColumn` has the same `summarize()` method. When any export column declares
a summarizer, Kinetix appends a **totals row** to the CSV/XLSX after the data:

```php
class OrdersExporter extends Exporter
{
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference'),
            ExportColumn::make('total')->summarize(Sum::make()->label('Total')),
        ];
    }
}
```

The summary is computed over the exported query (so a bulk export of selected
rows totals exactly those rows). `Exporter::hasSummary()` reports whether a row
will be written; set `protected bool $withSummary = false;` on the exporter to
suppress it.

---

## Stat cards (`stats()`)

KPI cards above the table — counts, sums and averages over the same dataset the
table lists. Optional: a table without `stats()` renders and queries exactly as
before.

```php
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\TableStat;

Table::make(Book::query())
    ->stats([
        TableStat::make('Total books')->count()->icon('book'),

        TableStat::make('On loan')
            ->count()
            ->where('status', 'loan')
            ->icon('bookmark')
            ->color('warning'),

        TableStat::make('Overdue')
            ->count()
            ->where('due_at', '<', now())
            ->icon('chart-bar')
            ->color('danger')
            ->url('/books?filters[overdue]=1'),

        TableStat::make('Inventory value')
            ->sum('price')
            ->money('USD')
            ->color('success'),
    ])
    ->columns([...]);
```

This works identically on a **Resource** — `Resource::table()` receives the same
`Table`, so declare the cards there:

```php
public static function table(Table $table): Table
{
    return $table
        ->stats([TableStat::make('Total')->count()])
        ->columns([...]);
}
```

### One query, however many cards

Each card's condition compiles into a **conditional aggregate** inside a single
shared query:

```sql
select count(*)                                            as kinetix_stat_0,
       sum(case when "status" = ? then 1 else 0 end)        as kinetix_stat_1,
       sum(case when "due_at" < ? then 1 else 0 end)        as kinetix_stat_2,
       sum("price")                                         as kinetix_stat_3
from "books" where ...
```

So two cards and twelve cards both cost **+1 query**. This is the reason the
feature exists instead of stacking `Summarizer::query()` scopes on a column —
each of those runs its own query, so cards would cost +1 query *each*, precisely
on the large tables where a scan is expensive. A test asserts the batching holds.

### Aggregates

| Method | Aggregate |
| --- | --- |
| `count()` | `count(*)` — the default |
| `sum('column')` | `sum(column)` |
| `avg('column')` | `avg(column)` |
| `min('column')` / `max('column')` | `min` / `max` |

### Conditions

`where()`, `whereNull()` and `whereNotNull()` narrow a single card without
affecting the others. They chain as `AND`:

```php
TableStat::make('Big overdue loans')
    ->count()
    ->where('status', 'loan')
    ->where('copies', '>=', 10)
    ->whereNotNull('due_at');
```

Values travel as bindings, and the operator is checked against an allowlist
(`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`, `not like`) — an unsupported one
throws rather than reaching the SQL.

### Filters

**By default a card reflects the table's active filters and search**, like the
footer summaries: filter the list to "on loan" and the cards describe that
subset. They always cover the whole filtered set, never just the current page.

For a KPI that should stay put while the user filters, call `ignoreFilters()`:

```php
TableStat::make('Books in catalogue')->count()->ignoreFilters(),
```

Cards that ignore filters share one aggregate query *with each other*, so mixing
both kinds costs two queries total, not one per card.

### Presentation

| Method | Effect |
| --- | --- |
| `icon('book')` | Lucide icon in the card's badge |
| `color('success')` | Badge colour: `primary`, `info`, `success`, `warning`, `danger`, `gray` |
| `description('This month')` | Secondary line under the value |
| `url('/books?…')` | Makes the whole card an Inertia link |
| `numeric(2)` / `money('USD', divideBy: 100)` | Value formatting (same helpers as summarizers) |
| `prefix()` / `suffix()` | Wrap the formatted value |

### Visibility and authorization

`visible()`, `hidden()` and `can()` behave as they do on columns — and a card the
user may not see is **never computed**: its aggregate is left out of the query
entirely rather than being fetched and discarded.

```php
TableStat::make('Revenue')->sum('total')->money('USD')->can('viewRevenue'),
```

### The escape hatch

`using()` takes the query and returns the value, for anything a single-pass
aggregate can't express. It cannot be batched, so it costs one query of its own —
deliberately explicit:

```php
TableStat::make('Distinct authors')
    ->using(fn (Builder $query) => $query->distinct()->count('author_id')),
```

::: tip Cards from a different model
`stats()` aggregates the table's own dataset. For a KPI over another model (an
"Active members" card above a books table), render a
[`StatsOverviewWidget`](/widgets) beside the table instead — it isn't bound to the
table's query, and you can cache it.
:::

---

## Table Configuration

Table-level methods control refresh, pagination, and row behavior:

- `poll(string $interval)`: Refreshes the table on an interval (e.g. `->poll('10s')`). Backed by Inertia's **`usePoll`** — a partial reload that preserves scroll and table state (search/filters/sort/page). Accepts `'10s'`, `'5000ms'`, etc.
- `reorderable(string $column = 'sort_order')`: Enables drag-and-drop row reordering (a grip-handle column appears). The new order is persisted to the given integer column via a signed, token-guarded `tables/reorder` endpoint, and rows default to that order. See [Reordering rows](#reordering-rows).
- `paginated(bool|array $options)`: Toggles pagination or sets the page-size options. Pass `false` to disable pagination (the full result set is rendered), or an array of integers to override the selectable per-page options (default `[5, 10, 25, 50]`).
- `simplePaginated(bool $simple = true)`: Paginate **without counting** the result set. See [Large tables](#large-tables-simplepaginated).
- `cursorPaginated(bool $cursor = true)`: Seek-based pagination — no `OFFSET`, constant cost at any depth. See [Deep pagination](#deep-pagination-cursorpaginated).
- `defaultPaginationPageOption(int $perPage)`: Sets the initial page size (default `10`).
- `recordUrl(Closure $callback)`: Makes the whole row clickable, resolving a URL per record: `->recordUrl(fn ($record) => route('posts.edit', $record))`.
- `recordModals(string $resource, ?string $source = null)`: Host create/edit/view modals inside the table itself, driven by the resource's `form()` and `infolist()`. Paired with actions flagged `->modal('create'|'edit'|'view'|'delete')`, a page becomes just `<KinetixTable :table>`. Edits fetch a fresh record from the server by default; pass `source: 'row'` (or set `kinetix.tables.record_source`) to prefill from the loaded row. See [Resources → Simple Resource](/resources#_2-simple-resource-simple).

```php
Table::make(Post::query())
    ->poll('10s')
    ->paginated([10, 25, 100])
    ->defaultPaginationPageOption(25)
    ->recordUrl(fn ($record) => route('posts.edit', $record));

// Disable pagination entirely
Table::make(Post::query())->paginated(false);
```

### Large tables — `simplePaginated()`

A normal `paginate()` runs a `COUNT(*)` over the **filtered** query on every page
load, just to know the total. On a table with hundreds of thousands of rows — or
one whose filters are expensive — that count dominates the request, and it runs
again on every search keystroke, filter change and page step.

```php
Table::make(AuditEntry::query())->simplePaginated();
```

Simple mode fetches one extra row instead, to learn whether a next page exists.
What you give up is what the count paid for:

| | `paginated()` | `simplePaginated()` |
|---|---|---|
| Query per page | rows + `COUNT(*)` | rows only (+1 row) |
| Footer label | *Showing 11–20 of 4,231* | *Showing 11–20* |
| Page indicator | *Page 2 of 424* | *Page 2* |
| First / last jump | ✅ | ✅ prev/next only |

The payload reflects this: `pagination.total` and `pagination.lastPage` are
`null`, and `pagination.hasMore` is the signal for whether a next page exists.
Custom footers must read `hasMore` rather than comparing against `lastPage`.

::: tip Summaries add exactly one scan
Column summarizers aggregate over the filtered set, so a table with both
`simplePaginated()` and `->summarize()` still pays for **one** scan — every plain
aggregate (sum, average, count, range) across every column is folded into a
single query. A summarizer with its own `query()` scope or a `using()` callback
has a different dataset, so it keeps its own query; the count you pay is
`1 + (scoped summarizers)`. Drop the summaries entirely if the goal is a page
with no scan at all.
:::

### Deep pagination — `cursorPaginated()`

`simplePaginated()` removes the count, but `OFFSET` remains: `LIMIT 10 OFFSET
50000` makes the database walk and throw away 50,000 rows, so page 5,000 is
thousands of times slower than page 1. A cursor **seeks** instead — `WHERE (sort,
id) > (last values)` — which uses the sort's index and costs the same at any
depth.

```php
Table::make(AuditEntry::query())->cursorPaginated();
```

| | `paginated()` | `simplePaginated()` | `cursorPaginated()` |
|---|---|---|---|
| `COUNT(*)` per page | ✅ | — | — |
| Cost at page 5,000 | walks 50k rows | walks 50k rows | same as page 1 |
| Navigation | any page | prev / next | prev / next |
| URL | `?page=2` | `?page=2` | `?cursor=eyJpZCI6…` |

#### The trap it avoids: skipped rows

A cursor is built from the `ORDER BY` columns. Sort by something **non-unique**
— `status`, a date, a name — and on a tie the next page resumes *after that
value*, stepping over the rest of the tied group. Nothing errors; rows simply
never appear. In a 6-row fixture sorted by a 2-value column, walking every page
returns **4 rows**.

Kinetix appends the primary key to the sort, making the ordering total, so the
walk is complete. You don't have to do anything — but if you build cursor queries
by hand elsewhere, add the tiebreaker yourself.

#### Sorts a cursor can't express

A relation column (`author.name`) sorts through a correlated subquery, and a
custom `sortable(using: …)` resolver can order by anything. Neither has a value
the cursor can encode. Rather than paginate wrongly, the table **falls back to
`simplePaginated()` for that request** — the footer switches to page-based
prev/next and everything keeps working.

#### Payload

`currentPage`, `total`, `lastPage`, `from` and `to` are all `null`; navigation
runs off `nextCursor` / `prevCursor`, with `onFirstPage` and `hasMore` as the
enable/disable signals. A custom footer must branch on
`pagination.currentPage === null` to detect cursor mode.

::: warning Shared links point at a position, not a page
`?cursor=…` encodes the last row of the previous page under the ordering in
effect when it was issued. Change the sort or the filters and that position is
meaningless — Kinetix drops the cursor automatically whenever search, sort,
filters or page size change, restarting from the first page.
:::

---

## Reordering rows

Call `reorderable()` to let users drag rows into a new order. A grip handle is
added to each row; on drop, Kinetix persists the new order to an integer column
(`sort_order` by default) and the table defaults to ordering by it.

```php
Table::make(Section::query())->reorderable(); // persists to `sort_order`
Table::make(Section::query())->reorderable('position'); // custom column
```

<Screenshot name="table-reorderable" alt="Reorderable table with drag handles, selection and status badges" />

Persistence goes through a signed `tables/reorder` endpoint: the reorder column
is baked into the same encrypted token as the table's model, so a request can
only reorder a table that explicitly opted in via `reorderable()` — the column
can't be forged from the client (the same guard as inline cell edits).

### How the write endpoints are guarded

Inline cell edits (`ToggleColumn`, `TextInputColumn`, `SelectColumn`) and
reordering both post to Kinetix-owned endpoints that trust **only** the table's
signed descriptor. That descriptor is defended on four axes:

| Axis | What it stops |
|---|---|
| **Scoping** | The record is resolved through the table's own constraints, so a tampered `recordId` outside the table is a 404, not a write. |
| **Authorization** | The model's policy decides — `update`, or the ability from `writeAbility()`. |
| **Binding** | The descriptor records the user it was minted for, so a token lifted from an admin's page (with a wider editable-column allowlist) is useless to anyone else. |
| **Freshness** | Descriptors expire after `kinetix.tables.token_ttl` minutes (default 1440). |

Scoping is automatic for the common case: Kinetix reads the base query's simple
`where` clauses when it mints the descriptor, so
`Table::make(Post::where('team_id', $id))` is already bounded. Declare it
explicitly when the constraints can't be introspected — a global scope, a nested
closure, a join:

```php
Table::make($this->postsQuery())
    ->writeScope(['team_id' => $request->user()->currentTeam->getKey()])
    ->writeAbility('publish') // optional: require something narrower than `update`
    ->columns([ToggleColumn::make('is_published')]);
```

::: warning Without a policy, scoping is the only boundary
When the model has no policy, Kinetix enforces nothing here — the host owns
access, exactly as with record actions and kanban moves. In a multi-tenant app
that means the scope (captured or declared) is what keeps one tenant out of
another's rows, so make sure your base query actually carries it.
:::

---

## Defining a Table as a Class

As an alternative to the inline fluent builder, you can subclass `Table` and override the `build*()` hooks. This keeps controllers thin and makes table definitions reusable.

```php
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Actions\Action;

class PostsTable extends Table
{
    protected function buildColumns(): array
    {
        return [
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('author.name')->label('Author'),
        ];
    }

    protected function buildFilters(): array
    {
        return [
            SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
        ];
    }

    protected function buildRecordActions(): array
    {
        return [
            Action::make('edit')->icon('edit')->url(fn ($record) => route('posts.edit', $record)),
        ];
    }
}
```

Available hooks: `buildColumns()`, `buildFilters()`, `buildRecordActions()`, `buildToolbarActions()`, `buildBulkActions()`, and `buildFooterActions()`. They are invoked in the constructor, so instantiate the class with the query as usual.

Render it in one call with the static `render()` helper, which instantiates the table and returns the serialized array:

```php
return inertia('Posts/Index', [
    'postsTable' => PostsTable::render(Post::with('author')),
]);
```

Fluent configuration still applies on top of the hooks (e.g. `PostsTable::make($query)->poll('10s')->toArray()`).

---

## Known Limitations

Confirmed in the current `Table` query resolver:

- **Searchable dot-notation is single-level.** A searchable column like `author.name` is split on the first `.` only and resolved with a single `whereHas('author', fn ($q) => $q->where('name', 'like', ...))`. Deeper paths (e.g. `author.team.name`) are not expanded into nested `whereHas` calls.
- **Relationship-column sorting is single-level.** A sortable `author.name` column sorts via a correlated subquery on the related table (`BelongsTo` / `HasOne`), with the sort key allowlisted against the defined columns. Deeper paths (`author.team.name`) and other relation types are not resolved — use a custom `->sortable(using: ...)` resolver for those.

---

## Security

Editable columns update database values instantly by sending XHR requests. To ensure security:
1. When serializing, the table builder generates an encrypted representation of the target Eloquent model class (`Crypt::encryptString`).
2. Cell update requests submit this encrypted token along with the record ID, column name, and new value.
3. The backend updates endpoint decrypts the model class, confirms its validity, verifies record existence, and updates the record safely.

---

## Spatie Laravel Data & TypeScript Integration

Kinetix uses Spatie Laravel Data under the hood to ensure full data integrity and allow developers to automatically export all table and notification DTOs as TypeScript type definitions.

### Auto-Generating TypeScript Types

To generate TypeScript types for Kinetix in your frontend:

1. Install Spatie's TypeScript transformer in your main application:
   ```bash
   vendor/bin/sail composer require spatie/laravel-typescript-transformer
   ```

2. Add Kinetix Data classes to the search path in your `config/typescript-transformer.php`:
   ```php
   'searching_paths' => [
       app_path(),
       base_path('vendor/happones/kinetix/src/Data'),
   ],
   ```

3. Run the compiler:
   ```bash
   vendor/bin/sail artisan typescript:transform
   ```
   This will generate types like `TableData`, `TableRowData`, `ColumnData`, etc. directly inside your frontend types (e.g., `resources/js/types/generated.d.ts`).

---

## Multi-Tenancy / Teams Support

If your application scopes its routes under a tenant or team slug (e.g., `{current_team}/posts`), Kinetix can automatically adapt its routing and closure parameters.

### Enabling Teams

To enable teams, set the `teams` parameter to `true` in your `config/kinetix.php`:

```php
'teams' => true,
```

When enabled:
- Kinetix automatically prefixes its internal API endpoints (e.g., cell updates, notification actions) under the active `{current_team}/`.
- Actions that evaluate closure URLs (like `fn ($record) => route('posts.edit', $record)`) will automatically inherit the active team parameter in their route parameters using URL defaults, avoiding `Missing required parameter: current_team` errors.

### The table does not scope your rows — you do

Routing and data isolation are separate layers. `Table::make($query)` renders
whatever query you hand it: Kinetix has no idea which column holds your tenant,
so a filter here would either guess or silently do nothing. The scoping seam is
`Resource::getEloquentQuery()`, which the index page **and** the in-table modal
endpoint both read — scope it once and modal CRUD is tenant-safe too:

```php
public static function getEloquentQuery(): Builder
{
    return Post::where('team_id', KinetixTeams::currentTeamKey());
}
```

Use `KinetixTeams::currentTeamKey()` rather than `$user->currentTeam`: it reads
the `{current_team}` segment (so a page served for team B never reads team A's
rows) and verifies the user belongs to that team. `kinetix:make-resource`
scaffolds exactly this when teams are on.

Search is applied **inside** a grouped `where()`, so its OR terms can never
escape your tenant filter and widen the result set.

---

## Enum Support & Contracts

When working with model attributes cast to Enums, Kinetix can automatically resolve their display labels, colors, icons, and select options using contracts (interfaces) and concerns (traits).

### Mapped Contracts

Implement the following contracts inside your Enums to provide metadata:

- `Happones\Kinetix\Support\Contracts\HasLabel`: Defines the human-friendly label for the enum.
- `Happones\Kinetix\Support\Contracts\HasColor`: Defines the theme color status (`primary`, `success`, `warning`, `danger`, `gray`) for badges and icons.
- `Happones\Kinetix\Support\Contracts\HasIcon`: Defines the Lucide icon name for icon columns.

#### Example Enum Definition

```php
namespace App\Enums;

use Happones\Kinetix\Support\Contracts\HasLabel;
use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasIcon;
use Happones\Kinetix\Support\Concerns\HasLabelOptions;

enum PostStatus: string implements HasLabel, HasColor, HasIcon
{
    use HasLabelOptions;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Published => 'Publicado',
            self::Archived => 'Archivado',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Published => 'success',
            self::Archived => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Draft => 'edit-3',
            self::Published => 'check-circle-2',
            self::Archived => 'x-circle',
        };
    }
}
```

### Column & Filter Integration

1. **TextColumn**: Automatically renders the value of `getLabel()` if the Enum implements `HasLabel`.
   - If `badge()` is enabled, it automatically uses the color returned by `getColor()` as the badge color if the Enum implements `HasColor`.
2. **IconColumn**: Automatically resolves the icon from `getIcon()` and the color from `getColor()` if the Enum implements `HasIcon` and `HasColor`.
3. **ColorColumn**: Automatically resolves the color value from `getColor()` if the Enum implements `HasColor`.
4. **SelectColumn & SelectFilter**: You can pass the Enum class directly to `options()`. It will automatically build the option list mapping values to labels:
   ```php
   // Dropdown options automatically generated from PostStatus Enum cases
   SelectColumn::make('status')->options(PostStatus::class)
   SelectFilter::make('status')->options(PostStatus::class)
   ```

### Reusable Concern: `HasLabelOptions`

Include `use Happones\Kinetix\Support\Concerns\HasLabelOptions;` inside your Enum to get a static helper method to generate option arrays:

```php
$options = PostStatus::options();
// Returns: ['draft' => 'Borrador', 'published' => 'Publicado', 'archived' => 'Archivado']
```

