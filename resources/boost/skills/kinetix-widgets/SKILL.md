---
name: kinetix-widgets
description: "Handles creation, layout grids, and rendering of Kinetix Widgets. Activates when adding/modifying statistical cards, tables overview, custom slots, and Unovis-based charts."
license: MIT
metadata:
  author: happones
---

# Kinetix Widgets Development

## When to Apply

Activate this skill when:
- Creating or editing dashboard metrics and widgets grids.
- Defining statistics overview lists using `Happones\Kinetix\Widgets\StatsOverviewWidget`.
- Instantiating area, bar, pie, doughnut, or line charts via `Happones\Kinetix\Widgets\ChartWidget`.
- Displaying lightweight summary tables inside widgets grids using `Happones\Kinetix\Widgets\TableWidget`.
- Showing a goal/quota progress bar or ring via `Happones\Kinetix\Widgets\ProgressWidget` (`->value()->target()->color()->ring()`).
- Injecting custom Vue components using `Happones\Kinetix\Widgets\CustomWidget` and custom slots.
- Switching a grid to a gap-free masonry layout via `WidgetsGrid::masonry()`, or backfilling gaps in the standard grid via `->dense()`.
- Positioning the existing live queue/health panels inside a grid via `Happones\Kinetix\Widgets\QueueStatsWidget` / `Happones\Kinetix\Widgets\HealthStatusWidget`.

## Documentation

For full details, reference the [Kinetix Widgets Documentation](file:///home/happones/Plugins/Php/kinetix/docs/widgets.md).

## Usage Guide

### 1. Backend Definition
Instantiate a layout grid, define the responsive columns per breakpoint, and chain the child widgets:

```php
use Happones\Kinetix\Widgets\WidgetsGrid;
use Happones\Kinetix\Widgets\StatsOverviewWidget;
use Happones\Kinetix\Widgets\Stats\Stat;
use Happones\Kinetix\Widgets\ChartWidget;

$grid = WidgetsGrid::make()
    ->columns([
        'default' => 12,
        'md' => 6,
        'lg' => 3,
    ])
    ->widgets([
        StatsOverviewWidget::make()
            ->sort(1)
            ->columnSpan('full')
            ->stats([
                Stat::make('New Registrations', 432)
                    ->description('8% growth')
                    ->descriptionIcon('trending-up')
                    ->descriptionColor('success')
                    ->chart([10, 15, 12, 18, 22]),
            ]),

        ChartWidget::make()
            ->sort(2)
            ->columnSpan([
                'default' => 12,
                'lg' => 2,
            ])
            ->chartType('line')
            ->labels(['Jan', 'Feb', 'Mar'])
            ->datasets([
                [
                    'label' => 'Total Orders',
                    'data' => [150, 210, 190],
                ]
            ]),
    ]);
```

### 2. Frontend Rendering
In your Inertia page, mount the grid and supply slots for any custom widgets if needed:

```vue
<script setup lang="ts">
import KinetixWidgetsGrid from '@/components/kinetix/KinetixWidgetsGrid.vue';
import type { KinetixWidgetsGridData } from '@/types';

defineProps<{
    widgetsGrid: KinetixWidgetsGridData;
}>();
</script>

<template>
    <KinetixWidgetsGrid :grid="widgetsGrid" />
</template>
```

---

## Best Practices

- **Sparkline Charts**: Stats cards render metric trends as lightweight SVG vector lines with gradient backgrounds matching the status color (`success`, `danger`, `warning`, `info`, `gray`). Avoid loading heavy external canvas charting modules inside stat cards.
- **Unovis / Shadcn Charting Conventions**: 
  - Charts are powered by `@unovis/vue` and `@unovis/ts`.
  - **IMPORTANT**: If your chart uses string category labels on the X-axis (e.g. `'Jan'`, `'Feb'`), mapping them directly will trigger continuous scale `NaN` errors. To prevent this, map the X coordinate to consecutive integers (`0, 1, 2, ...`). Supply those index coordinates to the `:tickValues` prop of `<VisAxis type="x" />`, and translate them back to string labels inside the `:tickFormat` lookup function.
- **Translations & Documentation**: Do not hardcode strings; always define them in translations and keep documentation updated for any new components or options.
- **Role/permission-gated widgets**: hide widgets from certain users with `->visible(bool|Closure)`, `->hidden(bool|Closure)`, or `->authorize(string|Closure|bool $ability, mixed $arguments = null)` (a Gate-based shorthand, e.g. `->authorize('viewFinancials')`). This is checked server-side in `WidgetsGrid::toArray()` **before** the widget's data is computed — an unauthorized user never receives the widget's payload, and its query never runs. Unlike Actions/Forms, a widget has no per-record pass, so a bare ability is checked immediately (no deferral); pass `$arguments` for abilities needing a subject.
- **Masonry & layout customization**: `->gap($value)` (CSS length or responsive map, default `'1.5rem'`), `->dense()` (`grid-auto-flow: dense` on the standard `columnSpan` grid — visual order may then differ from DOM order), `->masonry($columns = 3)` (true column-balanced layout — each widget occupies one column and `columnSpan` is ignored; best for widgets of similar width but varying height).
- **`columnSpan` doesn't auto-stack on mobile**: a bare `->columnSpan(4)` applies at every breakpoint. Always pass a responsive map for anything narrower than full width: `->columnSpan(['default' => 12, 'lg' => 4])`.
- **`QueueStatsWidget` / `HealthStatusWidget`**: thin wrappers with no `data` payload — they only position/gate the existing self-polling `<KinetixQueueStats>`/`<KinetixHealthStatus>` panels inside a grid (`->columnSpan()`, `->sort()`, `->authorize()`). The Vue components keep polling their own endpoints exactly as they do standalone.
