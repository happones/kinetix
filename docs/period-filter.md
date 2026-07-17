# Period Filter

The common dashboard date-range control — **Last 7 days / Last 30 days / This
month / …** — as a Vue component, a composable for the client-side range, and a
PHP parser that turns the selected period into a query range. Drop it in a widget
header (e.g. next to a chart title) so the dashboard re-scopes on change.

<Screenshot name="period-filter" alt="Period filter — segmented and select" />

---

## The component

```vue
<script setup lang="ts">
import KinetixPeriodFilter from '@/components/kinetix/KinetixPeriodFilter.vue';
import { useKinetixPeriod } from '@/composables/useKinetixPeriod';

// navigate: true pushes ?period=… to the server on change
const { period, range, setPeriod } = useKinetixPeriod('30d', { navigate: true });
</script>

<template>
    <KinetixPeriodFilter
        v-model="period"
        :periods="['7d', '30d', '90d']"
        variant="segmented"
        @change="setPeriod"
    />
</template>
```

| Prop      | Type                                    | Default            |
| --------- | --------------------------------------- | ------------------ |
| `modelValue` | period key                           | `30d`              |
| `periods` | `KinetixPeriodKey[]`                     | `['7d','30d','90d']` |
| `variant` | `segmented` \| `select`                  | `segmented`        |

Period keys: `today`, `yesterday`, `7d`, `30d`, `90d`, `month`, `year`, `all`.
Labels are localized (`period_*`, en/es/fr/pt).

---

## The composable

`useKinetixPeriod(initial?, { navigate?, only? })` returns:

- `period` — the selected key (initialized from `?period=` in the URL if present).
- `range` — `{ start, end }` ISO dates (`null` bounds for `all`), mirroring the
  PHP parser so client and server agree.
- `setPeriod(key)` — updates the period. With `navigate: true` it pushes
  `?period=` to the server (Inertia visit, `preserveState`/`preserveScroll`),
  optionally scoping the reload to `only` props.

---

## The PHP parser

Resolve the period in your controller and scope the query:

```php
use Happones\Kinetix\Support\Period;

public function index(Request $request)
{
    [$start, $end] = Period::fromRequest($request, default: '30d');

    $orders = Order::query()
        ->tap(fn ($q) => Period::scope($q, 'created_at', $request->input('period', '30d')))
        ->get();

    return inertia('Dashboard', [
        'orders' => $orders,
        'period' => $request->input('period', '30d'),
    ]);
}
```

- `Period::range(string $key, ?from, ?to): [CarbonImmutable|null, CarbonImmutable|null]` — the resolved bounds (`[null, null]` for `all`/unknown; `custom` uses `from`/`to`).
- `Period::fromRequest(Request, string $default): [start, end]` — reads `?period=` (+ `?from=&to=`).
- `Period::scope(Builder $query, string $column, string $key, ?from, ?to): Builder` — applies `>=`/`<=` bounds (no-op for `all`).

Because the same key set drives the Vue component, the composable and the PHP
parser, the filter "just works" end to end.
