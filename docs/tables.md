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

---

## Table Filters Reference

All filters reside in the `Happones\Kinetix\Tables\Filters` namespace.

### 1. `Filter`
Renders as a checkbox.
- `query(Closure $callback)`: Closure modifying the query builder: `fn (Builder $query, $value) => $query->where(...)`.
- `default(mixed $value)`: Default active state.

### 2. `SelectFilter`
Renders as a dropdown select.
- `options(array|Closure $options)`: Dropdown option pairs.
- `attribute(string $attribute)`: Maps query parameters directly to database columns. If omitted, defaults to the filter name.

---

## In-Table Record Actions

Add row-level action buttons at the end of each table line:

```php
$table->recordActions([
    Action::make('edit')->icon('edit')->url(fn ($record) => "/posts/{$record->id}/edit"),
    Action::make('delete')->icon('delete')->color('danger')->dispatch('delete-record'),
]);
```

Header-level toolbar actions can also be defined via `$table->toolbarActions([ ... ])`.

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

