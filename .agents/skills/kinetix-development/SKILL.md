---
name: kinetix-development
description: Development guide, structure rules, and best practices for Kinetix Notifications, Widgets, and Tables.
---

# Kinetix Development Skill

This skill contains the conventions, requirements, and implementation patterns for Kinetix **Notifications**, **Widgets**, and **Tables**. Refer to this guide whenever creating new features or refactoring components.

---

## 1. General Implementation Conventions

### PHP Rules
- **Explicit Types**: Use explicit return type declarations and parameter hints for all methods: `function isFeatured(Model $record): bool`.
- **Property Promotion**: Use PHP 8 constructor property promotion for dependency injection.
- **TitleCase Enum Keys**: Always write Enum keys in TitleCase.
- **Pint Formatter**: Always format modified PHP files before finishing changes.

### Vue & TypeScript Rules
- **TypeScript First**: Always develop Vue components with `<script setup lang="ts">`.
- **No Inline Types**: Place all TypeScript interfaces and types in `resources/js/types/index.ts`.
- **Flat Logic**: Avoid `else` or `else if` statements in script setups. Use early returns (`if (condition) { return; }`) to keep logic clean and readable.
- **Relative Sibling Imports**: Import sibling Vue components relatively (`./Sibling.vue`) to ensure paths do not break when published.
- **Pure-CSS Grid Variables**: To prevent Tailwind class purging, map responsive grid spans to inline CSS variables (e.g. `--col-span-md`) and resolve them inside `<style scoped>` media queries.

---

## 2. Kinetix Notifications

Kinetix Notifications provide backend-to-frontend notifications using Laravel Echo/Reverb and Inertia.

### Backend Dispatching
```php
use Happones\Kinetix\Notifications\Notification;
use Happones\Kinetix\Actions\Action;

Notification::make()
    ->title('Order Shipped')
    ->description('Your order #1084 has been successfully shipped.')
    ->success() // success, info, warning, danger
    ->actions([
        Action::make('track')
            ->label('Track Package')
            ->url('/orders/1084/track')
            ->button()
            ->color('primary')
            ->close(),
        
        Action::make('dismiss')
            ->label('Dismiss')
            ->link()
            ->color('gray')
            ->close(),
    ])
    ->broadcast($user);
```

### Key Frontend Components
- `KinetixNotificationTrigger.vue`: Renders the bell trigger and unread counter badge.
- `KinetixNotificationDrawer.vue`: The sliding sheet sidebar listing all database notifications.
- `KinetixNotificationItem.vue`: Individual notification item managing action dispatchers.

---

## 3. Kinetix Widgets

Widgets are modular metric layout blocks mapped to Inertia views.

### Backend Layout
```php
use Happones\Kinetix\Widgets\WidgetsGrid;
use Happones\Kinetix\Widgets\StatsOverviewWidget;
use Happones\Kinetix\Widgets\Stats\Stat;
use Happones\Kinetix\Widgets\ChartWidget;

$grid = WidgetsGrid::make()
    ->columns(['default' => 12, 'lg' => 3])
    ->widgets([
        StatsOverviewWidget::make()
            ->stats([
                Stat::make('Active Users', 1240)
                    ->description('3% increase')
                    ->descriptionIcon('trending-up')
                    ->descriptionColor('success')
                    ->chart([10, 12, 14, 11, 16]),
            ]),

        ChartWidget::make()
            ->chartType('line') // line, bar, pie, doughnut
            ->labels(['Jan', 'Feb', 'Mar'])
            ->datasets([
                ['label' => 'Revenue', 'data' => [400, 500, 480]]
            ])
    ]);
```

### Visual Sparklines
- Stats sparklines are drawn dynamically as **lightweight SVG paths** with gradient fills mapped to the status color (`success` = green, `danger` = red).

### Charting System (Unovis)
- All charts are rendered using **`@unovis/vue`** and **`@unovis/ts`** (the framework behind Shadcn Vue charts).
- **CRITICAL**: For line and bar XY charts, always map string labels to numeric indices in the data coordinates (`0, 1, 2, ...`). Use the `:tickValues` array to force ticks onto index coordinates and format them back to strings using `:tickFormat`. This prevents continuous scale `NaN` rendering exceptions in Unovis.

---

## 4. Kinetix Tables

Tables provide Eloquent query filtering, pagination, and inline database updates.

### Schema Definition
```php
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use App\Models\User;

$table = Table::make(User::query())
    ->heading('Users Directory')
    ->striped()
    ->columns([
        TextColumn::make('name')->searchable()->sortable(),
        TextColumn::make('email')->searchable(),
        ToggleColumn::make('is_active')->label('Status'),
    ])
    ->filters([
        SelectFilter::make('role')->options(['admin' => 'Admin', 'user' => 'User']),
    ]);
```

### Column Types
- `TextColumn`: String grids, badges, custom carbon date formats, currency formats, and text truncation.
- `IconColumn`: Boolean checks and conditional icon statuses.
- `ImageColumn`: Thumbnail previews with sizing and circular shape options.
- `ColorColumn`: Color swatches supporting one-click clipboard copying.
- **Inline Editors**: `SelectColumn`, `ToggleColumn`, `TextInputColumn`, and `CheckboxColumn` provide live database modifications.

### Security & Cell Updates
- In-table edits trigger XHR requests to `/tables/cell-update`.
- To prevent parameter tampering, the backend model class is securely encrypted (`Crypt::encryptString`) on serialization. The controller decrypts this token to validate and load the target model class dynamically.
