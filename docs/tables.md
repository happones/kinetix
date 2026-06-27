# Kinetix Tables

Kinetix provides a powerful, Eloquent-driven, and highly interactive Tables system. Using a fluent PHP builder API, you configure columns, filters, and actions, serialize the configuration and data to JSON, and render a responsive, features-rich grid using Vue 3, Inertia.js 3, and TypeScript.

---

## Key Features

- **Fluent Schema Definitions**: Define columns, filters, and row-level actions with chaining methods.
- **Eager Loaded Relationships**: Safe dot-notation (e.g. `author.name`) simplifies displaying relationship values without causing N+1 queries.
- **Dynamic Inline Editing**: Editable columns (Selects, Toggles, Text Inputs, Checkboxes) automatically perform background XHR updates to the database.
- **Security Signatures**: Eloquent model namespaces are securely encrypted on serialization, preventing client-side database class tampering.
- **Client-Side Column visibility**: Built-in column toggling lets users hide and show columns locally in the browser.
- **Interactive Headers**: Header triggers automate debounced searching, column visibility, active filters, and sorting.

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
    $table = Table::make(Post::with('author'))
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
import type { KinetixTableData } from '@/types';

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

---

## Column Builders Reference

All column classes inherit from `Column` and reside in the `Happones\Kinetix\Tables\Columns` namespace.

### Shared Column Methods
- `make(string $name)`: Instantiates a column. The name can use dot-notation for relationship fields (e.g. `user.profile.phone`).
- `label(string $label)`: Customizes the display label in the header.
- `searchable(bool $condition = true)`: Enables search queries against this column.
- `sortable(bool $condition = true)`: Enables active header sorting.
- `alignment(string $alignment)`: Sets horizontal alignment (`left`, `center`, `right`).
- `toggleable(bool $isToggleable = true, bool $isToggledHiddenByDefault = false)`: Allows users to hide/show the column.
- `copyable(bool $condition = true)`: Shows a click-to-copy button on the cell (on hover) that copies its value to the clipboard. Works on any column type.
- `formatStateUsing(Closure $callback)`: Formats the value dynamically on the backend before serialization.

### 1. `TextColumn`
Displays text strings with additional formatting structures:
- `badge()`: Wraps the value in a rounded badge.
- `badgeColor(string|Closure $color)`: Sets status colors (`success`, `danger`, `warning`, `info`, `gray`).
- `date(string $format)`: Formats carbon/datetime values.
- `dateTime(string $format)`: Formats carbon/datetime values.
- `money(string $currency)`: Prepends currency signs (defaults to USD).
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

### 3. `MultiSelectFilter`
Renders as a checkbox list; matches any selected value via `whereIn`. Extends `SelectFilter` (same `options()`, including Enum classes).

```php
use Happones\Kinetix\Tables\Filters\MultiSelectFilter;

MultiSelectFilter::make('status')->options(PostStatus::class);
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
| `->locale(string)` | BCP-47 locale for weekday/month names, e.g. `'es'`, `'fr'` |
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

TextColumn::make('rating')->numeric()->summarize([
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

## Table Configuration

Table-level methods control refresh, pagination, and row behavior:

- `poll(string $interval)`: Refreshes the table on an interval (e.g. `->poll('10s')`). Backed by Inertia's **`usePoll`** — a partial reload that preserves scroll and table state (search/filters/sort/page). Accepts `'10s'`, `'5000ms'`, etc.
- `reorderable(string $column = 'sort_order')`: Enables drag-and-drop row reordering (a grip-handle column appears). The new order is persisted to the given integer column via a signed, token-guarded `tables/reorder` endpoint, and rows default to that order. See [Reordering rows](#reordering-rows).
- `paginated(bool|array $options)`: Toggles pagination or sets the page-size options. Pass `false` to disable pagination (the full result set is rendered), or an array of integers to override the selectable per-page options (default `[5, 10, 25, 50]`).
- `defaultPaginationPageOption(int $perPage)`: Sets the initial page size (default `10`).
- `recordUrl(Closure $callback)`: Makes the whole row clickable, resolving a URL per record: `->recordUrl(fn ($record) => route('posts.edit', $record))`.

```php
Table::make(Post::query())
    ->poll('10s')
    ->paginated([10, 25, 100])
    ->defaultPaginationPageOption(25)
    ->recordUrl(fn ($record) => route('posts.edit', $record));

// Disable pagination entirely
Table::make(Post::query())->paginated(false);
```

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
- **Sorting on relationship/dotted columns is skipped.** Sort requests whose column name contains a `.` are ignored (no `orderBy` is applied) to avoid join validation errors. Only direct columns can be sorted.

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

