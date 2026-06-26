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

> **Already using `spatie/laravel-activitylog`?** Kinetix records to its own
> `kinetix_activity` table for consistent team-scoping and diff format. A read
> bridge that also surfaces spatie entries in the same feed is a planned,
> opt-in increment — see [`ROADMAP.md`](https://github.com/happones/kinetix/blob/main/ROADMAP.md).
