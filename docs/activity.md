# Activity Log

Kinetix Activity is a native, team-scoped **audit trail** plus a lightweight
**event spine**. Add a trait to a model to auto-record create/update/delete (with
an old→new diff), or log anything via the `KinetixActivity` facade. Read it back
with `<KinetixActivityLog>` — globally, or scoped to a single record so an admin
can see the change history of, say, one product.

---

## Installation

```bash
php artisan vendor:publish --tag=kinetix-activity-migrations
php artisan migrate
```

Enable it in `config/kinetix.php` (opt-in, default off):

```php
'activity' => [
    'enabled'        => env('KINETIX_ACTIVITY_ENABLED', false),
    // Storage driver: 'auto' uses spatie/laravel-activitylog when installed,
    // otherwise the native kinetix_activity table. Force with 'spatie' / 'native'.
    'driver'         => env('KINETIX_ACTIVITY_DRIVER', 'auto'),
    // Scope entries per team (null team = global).
    'teams'          => env('KINETIX_ACTIVITY_TEAMS', false),
    // Page size for the paginated feed.
    'per_page'       => env('KINETIX_ACTIVITY_PER_PAGE', 15),
    // Window kept by kinetix:activity:prune.
    'retention_days' => env('KINETIX_ACTIVITY_RETENTION_DAYS', 365),
],
```

---

## 1. Recording activity

### Auto-log a model

Add the `LogsKinetixActivity` trait — it records `created` / `updated` /
`deleted` with the causer (`auth()->user()`) and, for updates, the changed
attributes as an old→new diff:

```php
use Happones\Kinetix\Activity\Concerns\LogsKinetixActivity;

class Product extends Model
{
    use LogsKinetixActivity;

    // Optional: keep extra attributes out of the diff
    // protected function kinetixActivityIgnored(): array
    // {
    //     return ['created_at', 'updated_at', 'password', 'remember_token', 'internal_notes'];
    // }
}
```

### Log anything manually

```php
use Happones\Kinetix\Activity\KinetixActivity;

KinetixActivity::log('exported', $report, ['format' => 'csv']);
KinetixActivity::log('published', $post, causer: $admin, description: 'Scheduled publish');
```

Every record dispatches the `ActivityLogged` event (the spine) — listen to it to
react to domain changes (the Webhooks module will fan these out).

---

## 2. Reading activity

`<KinetixActivityLog>` is self-loading and paginated ("load more"). Scope it with
`subject-type` + `subject-id`, or omit them for the global, team-scoped feed.

### Per-feature: one record's history

Drop it onto a Resource's [View / Show page](/resources#_7-view-show-page-read-only),
so an admin sees that record's changes:

```vue
<script setup lang="ts">
import KinetixActivityLog from '@/components/kinetix/KinetixActivityLog.vue'

defineProps<{ product: { id: number } }>()
</script>

<template>
  <KinetixActivityLog subject-type="App\\Models\\Product" :subject-id="product.id" />
</template>
```

### Global feed (an audit page)

```vue
<KinetixActivityLog />
```

<Screenshot name="activity-log" alt="Activity log feed" />

Entries render a localized line — **`Created by Jane`**, **`Updated by Mo`** — and
updates list the changed fields. Descriptions are composed from i18n keys
(`activity_event_*`, `activity_by`, `activity_system`), so they translate across
en / es / fr / pt.

Need a custom UI? Use the `useKinetixActivity()` composable — `load(params)`
returns `{ data, pagination }` (filters: `subject_type`, `subject_id`, `event`,
`page`).

---

## 3. Authorization, teams & best practices

- The read endpoint is gated by the **`activity.view`** ability (auto-registers
  with the permission matrix when the Permissions module is on).
- With `activity.teams` on, entries are recorded and read within the user's
  `currentTeam` (null = global) — same bridge as Permissions/Settings.
- The feed is **always paginated** (`per_page`) and **eager-loads the causer** (no
  N+1). The table is indexed on `(subject_type, subject_id)`, `(causer_type,
  causer_id)`, `team_id` and `created_at`.
- **Retention**: schedule `kinetix:activity:prune` so the table stays bounded:

  ```php
  // routes/console.php
  Schedule::command('kinetix:activity:prune')->daily();
  ```

---

## 4. Endpoint

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/activity` | Paginated feed; filters `subject_type` / `subject_id` / `event` / `page` (gated `activity.view`) |

---

## 5. Storage driver — spatie/laravel-activitylog

`spatie/laravel-activitylog` is the de-facto standard, so Kinetix **prefers it
when installed** rather than reimplementing audit logging:

```bash
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

With `driver = auto` (the default), Kinetix detects spatie and logs through it;
otherwise it falls back to the native `kinetix_activity` table (so the feature
works with zero extra dependencies). Force either with `driver = 'spatie'` /
`'native'`. **Both drivers normalize to the same `ActivityData` DTO** — the
`<KinetixActivityLog>` component and the endpoint don't change.

- **Team-scoping** with the spatie driver is carried inside `properties.team_id`,
  so **no change to spatie's schema** is required; the native driver uses a
  `team_id` column.
- **Retention**: with the spatie driver, `kinetix:activity:prune` delegates to
  spatie's own `activitylog:clean` (one source of truth); the native driver
  deletes from `kinetix_activity` directly.
- **The diff format is identical** across drivers because the
  `LogsKinetixActivity` trait computes `{ old, attributes }` itself before
  handing off to the driver.
