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
- Creating inline cell editors (`SelectColumn`, `ToggleColumn`, `TextInputColumn`, `CheckboxColumn`).
- Appending query filters: `Filter` (checkbox), `SelectFilter`, `MultiSelectFilter` (whereIn), `TernaryFilter` (boolean tri-state), `DateFilter`, `DateTimeFilter`, `DateRangeFilter` (with optional `->calendar()` shadcn/Reka range calendar), `NumberRangeFilter`.
- Attaching row-level record actions or header toolbar buttons.
- Styling table rows with custom CSS background status classes.

## Documentation

For full details, reference the [Kinetix Tables Documentation](file:///home/happones/Plugins/Php/kinetix/docs/tables.md).

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

## Best Practices

- **Avoid N+1 Relationship Queries**: Eager-load all relationships on the backend query definition (e.g. `Product::with('category')`). The columns extract value state cleanly using dot-notation (`category.name`).
- **Inline Editing Architecture**: Inline editors trigger XHR updates to `/tables/cell-update`. To prevent tampering, the model class string is encrypted (`Crypt::encryptString`) on serialization and validated before updating attributes.
- **Client-Side Column Visibility**: Visible columns are managed client-side using `visibleColumnNames: Set<string>` inside `KinetixTable.vue`. Toggles are instant and do not require server request updates.
- **TypeScript Type Sync**: Annotate Spatie Data classes with `#[TypeScript]` and run `php artisan typescript:transform` in the parent application to generate frontend types automatically.
- **Teams & Multi-Tenancy**: When routing under a `{current_team}` prefix, toggle `'teams' => true` in `config/kinetix.php` to ensure Kinetix's API endpoints match and closure actions inherit the active team parameters natively.
- **Shadcn Checkboxes**: Always use `<KinetixCheckbox>` for table filters, column toggles, and editable checkbox cells to ensure consistent UI styling.
- **Translations & Documentation**: Do not hardcode strings; always define them in translations and keep documentation updated for any new components or options.
