# System Health Widget

A lightweight, embeddable **application-health widget** powered by
[spatie/laravel-health](https://spatie.be/docs/laravel-health). It surfaces the
latest stored check results — an overall status badge plus a per-check list —
right inside your Kinetix dashboard. Like the [Queue widget](/queue), it
complements rather than replaces a full health page.

<Screenshot name="health-status" alt="System health widget" />

---

## Requirements

Install and configure spatie/laravel-health, register your checks, and schedule
them so results are stored:

```bash
composer require spatie/laravel-health
```

```php
// app/Providers/AppServiceProvider.php
use Spatie\Health\Facades\Health;
use Spatie\Health\Checks\Checks\{DatabaseCheck, UsedDiskSpaceCheck, CacheCheck};

Health::checks([
    DatabaseCheck::new(),
    CacheCheck::new(),
    UsedDiskSpaceCheck::new(),
]);
```

```php
// schedule the checks (bootstrap/app.php or console kernel)
$schedule->command('health:check')->everyMinute();
```

The widget reads the **stored** results, so it reflects the last scheduled run.

---

## Installation

```php
'health' => [
    'enabled' => env('KINETIX_HEALTH_ENABLED', true),

    // Frontend poll interval (ms).
    'poll' => env('KINETIX_HEALTH_POLL', 30000),
],
```

### Authorization

The endpoint is gated by the `viewKinetixHealth` ability. Kinetix defines a
default that **allows it only in `local`** — define your own for production:

```php
Gate::define('viewKinetixHealth', fn ($user) => $user->isAdmin());
```

---

## The component

```vue
<script setup lang="ts">
import KinetixHealthStatus from '@/components/KinetixHealthStatus.vue';
</script>

<template>
    <KinetixHealthStatus />
</template>
```

An overall **status badge** (Healthy / Warning / Failing — the worst of all
checks) and a list of checks, each with a status icon and its short summary. It
polls the endpoint on the configured interval and stops on unmount. When
spatie/laravel-health isn't installed (or no checks have run), it shows an
"unavailable" message.

`useKinetixHealth()` exposes `{ snapshot, loading, failed, load, start, stop }`
for a custom UI. Strings are localized (`health_*`, en/es/fr/pt).

---

## The snapshot

`GET {prefix}/health` (gated) returns:

```ts
{
  available: boolean,                          // is spatie/laravel-health present + results stored?
  status: 'ok' | 'warning' | 'failed' | null,  // worst-of across checks
  checkedAt: string | null,                    // when the checks last ran
  checks: { name, label, status, message }[],
}
```
