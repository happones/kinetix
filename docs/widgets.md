# Kinetix Widgets

Kinetix provides a powerful, modular, and class-based Widgets system for building rich dashboard grids in Laravel applications. Using a fluent PHP builder API, you serialize configuration data to JSON and render responsive layout structures instantly using Vue 3, Inertia.js 3, and TypeScript.

---

## Key Features

- **Fluent PHP Builder**: Construct widgets on the backend using chaining methods.
- **JSON Serialization**: Full support for `Arrayable` and `JsonSerializable` to output lightweight configurations directly to Inertia views.
- **Pure CSS Layout Grid**: Dynamic CSS custom properties map responsive column spans across breakpoints (`sm`, `md`, `lg`, `xl`, `2xl`) without dynamic Tailwind class compilation problems.
- **Visual Sparklines**: Stats overview cards render SVG sparklines directly with automatic gradient colors matching the trend status.
- **Shadcn Charts (Unovis) Integration**: Renders vector-based charts with crosshairs, tooltips, and legends matching light and dark themes.
- **Custom Slot Fallback**: Custom widgets register unique slots (`v-slot`) to render highly specialized or interactive Vue components.

---

## Quick Start Example

### 1. Define the Grid on the Backend

In your controller or page component, build the `WidgetsGrid` config and send it to your Inertia view:

```php
use Happones\Kinetix\Widgets\WidgetsGrid;
use Happones\Kinetix\Widgets\StatsOverviewWidget;
use Happones\Kinetix\Widgets\Stats\Stat;
use Happones\Kinetix\Widgets\ChartWidget;
use Happones\Kinetix\Widgets\TableWidget;
use Happones\Kinetix\Widgets\CustomWidget;

public function index()
{
    $grid = WidgetsGrid::make()
        ->columns([
            'default' => 12,
            'md' => 4,
            'lg' => 3,
        ])
        ->widgets([
            StatsOverviewWidget::make()
                ->sort(1)
                ->columnSpan('full')
                ->stats([
                    Stat::make('New Customers', 1204)
                        ->description('12% increase')
                        ->descriptionIcon('trending-up')
                        ->descriptionColor('success')
                        ->chart([10, 14, 18, 12, 16, 24]),
                    
                    Stat::make('Conversions', '3.2%')
                        ->description('0.8% decrease')
                        ->descriptionIcon('trending-down')
                        ->descriptionColor('danger')
                        ->chart([15, 14, 11, 10, 8, 9]),

                    Stat::make('Avg Order Value', '$84.50')
                        ->description('Stable')
                        ->descriptionIcon('activity')
                        ->descriptionColor('info')
                        ->chart([80, 82, 85, 84, 84, 84]),
                ]),

            ChartWidget::make()
                ->id('sales_chart')
                ->title('Sales Overview')
                ->description('Monthly sales progression')
                ->columnSpan([
                    'default' => 12,
                    'lg' => 2,
                ])
                ->chartType('line')
                ->labels(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'])
                ->datasets([
                    [
                        'label' => 'Total Orders',
                        'data' => [120, 190, 300, 500, 200, 300],
                        'borderColor' => '#0ea5e9',
                        'backgroundColor' => 'rgba(14, 165, 233, 0.1)',
                        'fill' => true,
                    ]
                ]),

            TableWidget::make()
                ->title('Recent Transactions')
                ->columnSpan([
                    'default' => 12,
                    'lg' => 1,
                ])
                ->headers(['Order ID', 'Customer', 'Amount'])
                ->rows([
                    ['#1001', 'John Doe', '$120.00'],
                    ['#1002', 'Jane Smith', '$84.50'],
                    ['#1003', 'Bob Johnson', '$240.00'],
                ]),

            CustomWidget::make()
                ->id('custom_welcome')
                ->title('Welcome Card')
                ->columnSpan('full')
                ->properties([
                    'greeting' => 'Welcome back, Admin!',
                ])
        ]);

    return inertia('Dashboard', [
        'widgetsGrid' => $grid->toArray(),
    ]);
}
```

### 2. Render in Vue 3 Component

Import `KinetixWidgetsGrid` into your page template and supply custom slots for the ID of any custom widgets:

```vue
<script setup lang="ts">
import KinetixWidgetsGrid from '@/Components/KinetixWidgetsGrid.vue';
import type { KinetixWidgetsGridData } from '@/types';

defineProps<{
    widgetsGrid: KinetixWidgetsGridData;
}>();
</script>

<template>
    <div class="p-6 max-w-7xl mx-auto">
        <!-- Render the entire grid -->
        <KinetixWidgetsGrid :grid="widgetsGrid">
            <!-- Custom Slot matching custom widget ID -->
            <template #custom_welcome="{ widget }">
                <div class="p-4 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg text-white">
                    <h2 class="text-xl font-bold">{{ widget.data.greeting }}</h2>
                    <p class="text-xs text-indigo-100 mt-1">Here is what is happening today.</p>
                </div>
            </template>
        </KinetixWidgetsGrid>
    </div>
</template>
```

---

## Class Builders

### 1. `WidgetsGrid`

Organizes widgets in a responsive layout.

- `widgets(array $widgets)`: Sets the child widgets. Widgets are automatically sorted by `$sort` ascending inside `toArray()`.
- `columns(int|array $columns)`: Grid layout column configurations. Pass an integer or a breakpoint key-value array:
  ```php
  $grid->columns([
      'default' => 12,
      'sm' => 6,
      'md' => 4,
      'lg' => 3,
      'xl' => 2,
      '2xl' => 1
  ]);
  ```

### 2. `Widget` (Base Class)

All widgets inherit these shared methods:

- `id(string $id)`: Sets a unique key for the widget (essential for custom slots).
- `title(string $title)`: Section header displayed on top of the card.
- `description(string $description)`: Subtext displayed below the title.
- `sort(int $sort)`: The ordering placement index inside the grid.
- `columnSpan(int|string|array $columnSpan)`: How many grid columns the widget spans. Can be `full`, an integer, or an array mapped to responsive breakpoints:
  ```php
  $widget->columnSpan([
      'default' => 12,
      'md' => 6,
      'lg' => 4
  ]);
  ```

### 3. `StatsOverviewWidget`

Displays a list of statistical key-performance cards.

- `stats(array $stats)`: Array of `Stat` builders.

#### `Stat` Builder Class:
- `Stat::make(string $label, mixed $value)`: Instantiates a stat card.
- `description(string $description)`: Text line displayed below the stat.
- `descriptionIcon(string $icon)`: Lucide icon name or matching Heroicon identifier.
- `descriptionColor(string $color)`: Color status theme (`success`, `danger`, `warning`, `info`, `gray`).
- `chart(array $chart)`: Flat array of numeric trend values (e.g. `[12, 10, 15, 8]`) to render a responsive SVG line chart inside the card.

### 4. `ChartWidget`

Renders clean interactive charts utilizing `@unovis/vue` (Shadcn Vue Charts).

- `chartType(string $chartType)`: Chart types (`line`, `bar`, `pie`, `doughnut`).
- `labels(array $labels)`: X-axis or categorical labels array.
- `datasets(array $datasets)`: Series dataset configurations.
- `options(array $options)`: Deep options to customize chart properties.

### 5. `TableWidget`

Draws responsive summary tables.

- `headers(array $headers)`: List of columns headers.
- `rows(array $rows)`: List of rows. Rows can be either key-value objects or simple arrays.

### 6. `CustomWidget`

A wrapper slot card.

- `properties(array $properties)`: Custom payload data that is passed back to the Vue slot for rendering custom component templates.

---

## Responsive Design

Rather than appending arbitrary strings which can bypass Tailwind compilation or build purgers, `KinetixWidgetsGrid` computes inline style CSS custom properties:

```css
--grid-columns-default: 12;
--col-span-default: 6;
```

These are mapped to layout media queries inside `<style scoped>`:

- `sm`: `min-width: 640px`
- `md`: `min-width: 768px`
- `lg`: `min-width: 1024px`
- `xl`: `min-width: 1280px`
- `2xl`: `min-width: 1536px`

This ensures that layouts stay perfectly aligned with browser resizing while preserving performance.
