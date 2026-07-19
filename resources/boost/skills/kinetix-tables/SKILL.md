---
name: kinetix-tables
description: "Handles database listings, query filters, search queries, pagination, and inline cell editing in Kinetix. Activates when creating tables, columns, checkbox/select/input/toggle filters, and record actions."
license: MIT
metadata:
  author: happones
---

# Kinetix Tables Development

## When to Apply

Activate this skill when:
- Designing database list grids for model directories.
- Registering column types (`TextColumn`, `IconColumn`, `ImageColumn`, `ColorColumn`) to format model values.
- Creating inline cell editors (`SelectColumn`, `ToggleColumn`, `TextInputColumn`, `CheckboxColumn`, `NumberInputColumn` (steppers + decimal/percent/currency)).
- Adding footer **summaries** with `Column::summarize(Sum/Average/Count/Range/custom)` (also `ExportColumn::summarize()` to append a totals row to exports).
- Appending query filters: `Filter` (checkbox), `SelectFilter`, `MultiSelectFilter` (whereIn), `TernaryFilter` (boolean tri-state), `DateFilter`, `DateTimeFilter`, `DateRangeFilter` (with optional `->calendar()` shadcn/Reka range calendar), `NumberRangeFilter`, `MonthFilter`/`YearFilter`/`WeekFilter`, `AddressFilter` (OR-LIKE text search across `->columns([...])`).
- Localized dates: `TextColumn::date()`/`dateTime()` with no argument format via Carbon `isoFormat()` in the **app locale** (`config('kinetix.formats.date'|'datetime')`, defaults `ll`/`lll`); a format string = plain PHP `format()`; `isoDate()`/`isoDateTime()` take explicit iso tokens; `->locale()` overrides per column. Date filters' calendars also default to the app locale. `money($currency, $divideBy=1, $locale=null)` formats via intl in the app locale (divideBy=100 for cents).
- Filament-compatible sugar: `Column::state(fn ($record) => …)` (alias `getStateUsing()`) overrides the raw cell value before `formatStateUsing()`; `SelectFilter`/`MultiSelectFilter` `->relationship('author', 'name', ?Closure)` builds options from the related model and filters via `whereHas` (prefer `searchUsing()` for large related tables).
- Attaching row-level record actions or header toolbar buttons.
- Styling table rows with custom CSS background status classes.
- **Sorting by relationship columns**: `TextColumn::make('author.name')->sortable()` sorts via a correlated subquery (BelongsTo/HasOne). The sort key is allowlisted to defined sortable columns. Custom/multi-column sort: `->sortable(using: fn (Builder $q, string $dir) => $q->orderBy(...))`.
- **Client-side (TanStack) mode**: `Table::make(...)->clientSide()` ships the full (capped, default 500) row set once and a TanStack engine does search/sort/pagination in-browser — no round-trip. Same PHP API; `<KinetixTable>` lazy-loads the renderer only when `clientSide` is set. Needs the optional peer `@tanstack/vue-table`. Best for small datasets; omits server-only features (interactive filters, saved views, polling, reorder, bulk actions) — keep the default server-driven mode for those or for large data.

## Documentation

For full details, reference the [Kinetix Tables Documentation](file:///home/happones/Plugins/Php/kinetix/docs/tables.md).

## Localizing labels

Any display string you set is **your app's copy** — wrap it in Laravel's `__()` so
it's translatable: `$table->heading(__('posts.table.heading'))`,
`TextColumn::make('title')->label(__('posts.fields.title'))`, and filter/select
**option labels** (`->options(['draft' => __('posts.status.draft')])`). Columns with
no explicit `->label()` are auto-humanized (no wrapping needed). See the
**kinetix-locale** skill.

## Usage Guide

### 1. Backend Schema Definition
Set up the Model query, define the columns, add active filters, and configure actions:

```php
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ImageColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Actions\Action;
use App\Models\Product;

$table = Table::make(Product::query())
    ->heading('Inventory Manager')
    ->striped()
    ->columns([
        ImageColumn::make('image_url')
            ->label('Preview')
            ->circular(),

        TextColumn::make('title')
            ->searchable()
            ->sortable(),

        TextColumn::make('category.name')
            ->label('Category'),

        TextColumn::make('price')
            ->money('USD')
            ->sortable(),

        ToggleColumn::make('in_stock')
            ->label('In Stock'),
    ])
    ->filters([
        SelectFilter::make('supplier_id')
            ->options([1 => 'Supplier A', 2 => 'Supplier B']),
    ])
    ->recordActions([
        Action::make('edit')
            ->icon('edit')
            ->url(fn ($record) => route('products.edit', $record)),
    ]);
```

### 2. Frontend Rendering
Include `<KinetixTable />` in your template and bind the table data structure:

```vue
<script setup lang="ts">
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import type { KinetixTableData } from '@/types';

defineProps<{
    productsTable: KinetixTableData;
}>();
</script>

<template>
    <KinetixTable :table="productsTable" />
</template>
```

---

## Summaries

```php
use Happones\Kinetix\Tables\Columns\Summarizers\{Sum, Average, Count, Range};

TextColumn::make('price')->summarize(Sum::make()->money('EUR'));
TextColumn::make('rating')->summarize([Average::make()->numeric(1), Range::make()]);
IconColumn::make('is_published')
    ->summarize(Count::make()->query(fn ($q) => $q->where('is_published', true)));
```

- Summarizers (`Tables\Columns\Summarizers`): `Sum`, `Average`, `Count`, `Range`, and `Summarizer::make()->using(fn ($q) => …)` for custom. Shared: `label()`, `query()` (scope), `prefix()/suffix()`, `numeric()/money()`, `hidden()/visible()`.
- Computed over the **full filtered dataset** and rendered in a `<tfoot>` row (`TableData.summaries` / `hasSummaries`; `ColumnData.hasSummary`).
- **Exports**: `ExportColumn::summarize(...)` appends a totals row to CSV/XLSX (over the exported query, so bulk-selected exports total exactly those rows). `Exporter::hasSummary()`; disable with `protected bool $withSummary = false`.

## Best Practices

- **Avoid N+1 Relationship Queries**: Eager-load all relationships on the backend query definition (e.g. `Product::with('category')`). The columns extract value state cleanly using dot-notation (`category.name`).
- **Inline Editing Architecture**: Inline editors trigger XHR updates to `/tables/cell-update`. To prevent tampering, the model class string is encrypted (`Crypt::encryptString`) on serialization and validated before updating attributes.
- **Client-Side Column Visibility**: Visible columns are managed client-side using `visibleColumnNames: Set<string>` inside `KinetixTable.vue`. Toggles are instant and do not require server request updates.
- **TypeScript Type Sync**: Annotate Spatie Data classes with `#[TypeScript]` and run `php artisan typescript:transform` in the parent application to generate frontend types automatically.
- **Teams & Multi-Tenancy**: When routing under a `{current_team}` prefix, toggle `'teams' => true` in `config/kinetix.php` to ensure Kinetix's API endpoints match and closure actions inherit the active team parameters natively.
- **Shadcn Checkboxes**: Always use `<KinetixCheckbox>` for table filters, column toggles, and editable checkbox cells to ensure consistent UI styling.
- **Translations & Documentation**: Do not hardcode strings; always define them in translations and keep documentation updated for any new components or options.
