---
name: kinetix-queue
description: "A lightweight, embeddable queue-health widget that complements (not replaces) the Horizon dashboard. Reads Horizon metrics when installed, falls back to queue sizes + failed_jobs. Activates when adding queue metrics / a jobs dashboard widget."
license: MIT
metadata:
  author: happones
---

# Kinetix Queue Health Widget

## When to Apply

Activate this skill when:
- Adding queue/jobs metrics to a Kinetix dashboard (throughput, failed, pending).
- Surfacing a glanceable queue-health summary alongside other widgets.

It complements Laravel Horizon's full dashboard — it does not reproduce it.

## Documentation

For full details, reference `docs/queue.md` (published at https://happones.github.io/kinetix/queue).

## Configuration

```php
'queue' => [
    'enabled' => env('KINETIX_QUEUE_ENABLED', false),
    'queues'  => [ // monitored only when Horizon isn't installed
        ['connection' => null, 'queue' => 'default'],
    ],
    'poll' => env('KINETIX_QUEUE_POLL', 5000),
],
```

### Authorization

Gated by the `viewKinetixQueue` ability (defaults to allow in `local` only):

```php
Gate::define('viewKinetixQueue', fn ($user) => $user->isAdmin());
```

## Backend

- `QueueMetrics::snapshot()` → `{ horizon, status, throughput, recentJobs,
  failedJobs, queues:[{name,connection,size,wait}] }`. Uses Horizon's
  repositories when installed (throughput, recent, wait, supervisor status);
  otherwise queue sizes + the `failed_jobs` table. Never throws.
- `GET {prefix}/queue` returns the snapshot (gated, team-aware).

## Frontend

```vue
<KinetixQueueStats />
```

Status badge + throughput / recent / pending / failed tiles + per-queue rows;
polls on the shared interval, stops on unmount. `useKinetixQueue()` →
`{ snapshot, loading, failed, load, start, stop }`. i18n `queue_*`.
