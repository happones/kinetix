# Queue Health Widget

A lightweight, embeddable **queue-health widget** for your Kinetix dashboard. It
does **not** replace the [Laravel Horizon](https://laravel.com/docs/horizon)
dashboard — it surfaces a few live metrics (throughput, recent & failed jobs,
pending depth per queue) right where you want them. When Horizon is installed it
reads Horizon's metrics; otherwise it falls back to queue sizes + the
`failed_jobs` table, so it works on any queue driver.

<Screenshot name="queue-stats" alt="Queue health widget" />

---

## Installation

```php
'queue' => [
    'enabled' => env('KINETIX_QUEUE_ENABLED', true),

    // Queues to monitor when Horizon isn't installed (`connection: null` = default).
    'queues' => [
        ['connection' => null, 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'emails'],
    ],

    // Frontend poll interval (ms).
    'poll' => env('KINETIX_QUEUE_POLL', 5000),
],
```

When Horizon **is** installed, the `queues` list is ignored — the widget reads
Horizon's live workload (every queue, with wait times) instead.

### Authorization

The metrics endpoint is gated by the `viewKinetixQueue` ability. Kinetix defines
a default that **allows it only in `local`** — define your own to open it up in
production:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

Gate::define('viewKinetixQueue', fn ($user) => $user->isAdmin());
```

---

## The component

```vue
<script setup lang="ts">
import KinetixQueueStats from '@/components/KinetixQueueStats.vue';
</script>

<template>
    <KinetixQueueStats />
</template>
```

It shows a Horizon **status badge** (running / paused / inactive, when Horizon is
present), stat tiles for **per-minute throughput**, **recent jobs** (last hour),
**pending** and **failed**, then a per-queue list with depth and wait time. It
polls the endpoint on the configured interval and stops on unmount.

`useKinetixQueue()` exposes `{ snapshot, loading, failed, load, start, stop }`
for a custom UI. Strings are localized (`queue_*`, en/es/fr/pt).

---

## The snapshot

`GET {prefix}/queue` (gated) returns:

```ts
{
  horizon: boolean,                 // is Horizon driving the metrics?
  status: 'running' | 'paused' | 'inactive' | null,
  throughput: number | null,        // jobs/min (Horizon only)
  recentJobs: number | null,        // last hour (Horizon only)
  failedJobs: number,
  queues: { name, connection, size, wait }[],
}
```

`throughput`, `recentJobs` and `status` are `null` without Horizon; `wait` is
`null` per queue without it. The widget adapts — tiles that have no data are
hidden.

---

## Why a widget, not a dashboard

Horizon already ships an excellent, full-featured dashboard. Reproducing it would
be redundant. This widget is for the common case: *"show me queue health on my
own admin dashboard, next to my other Kinetix widgets"* — a glanceable summary,
with a link to Horizon for the deep dive.
