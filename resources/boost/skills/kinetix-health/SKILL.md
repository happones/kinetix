---
name: kinetix-health
description: "A lightweight, embeddable application-health widget powered by spatie/laravel-health. Reads the latest stored check results into an overall status + per-check list. Activates when adding a health/status widget."
license: MIT
metadata:
  author: happones
---

# Kinetix System Health Widget

## When to Apply

Activate this skill when:
- Adding an application-health / status widget to a Kinetix dashboard.
- Surfacing spatie/laravel-health check results in the SPA.

It complements a full health page rather than reproducing it.

## Documentation

For full details, reference `docs/health.md` (published at https://happones.github.io/kinetix/health).

## Requirements

Install spatie/laravel-health, register checks, and schedule `health:check` so
results are stored:

```bash
composer require spatie/laravel-health
```

## Configuration

```php
'health' => [
    'enabled' => env('KINETIX_HEALTH_ENABLED', false),
    'poll'    => env('KINETIX_HEALTH_POLL', 30000),
],
```

### Authorization

Gated by the `viewKinetixHealth` ability (defaults to allow in `local` only):

```php
Gate::define('viewKinetixHealth', fn ($user) => $user->isAdmin());
```

## Backend

- `HealthMetrics::snapshot()` → `{ available, status, checkedAt,
  checks:[{name,label,status,message}] }`. Reads the latest stored results from
  spatie/laravel-health; derives a worst-of overall status. Reports
  `available: false` (never throws) without the package.
- `GET {prefix}/health` returns the snapshot (gated, team-aware).

## Frontend

```vue
<KinetixHealthStatus />
```

Overall status badge + per-check rows (status icon + summary); polls on the
shared interval, stops on unmount. `useKinetixHealth()` →
`{ snapshot, loading, failed, load, start, stop }`. i18n `health_*`.
