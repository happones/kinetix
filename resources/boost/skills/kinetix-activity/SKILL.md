---
name: kinetix-activity
description: "Native, team-scoped audit trail + event spine. Activates when auto-logging model changes (LogsKinetixActivity), recording activity via KinetixActivity, rendering an activity timeline (global or per-record), or pruning old entries."
license: MIT
metadata:
  author: happones
---

# Kinetix Activity Development

## When to Apply

Activate this skill when:
- Auto-logging a model's create/update/delete with the `LogsKinetixActivity` trait.
- Recording arbitrary activity via the `KinetixActivity` facade, or listening to
  the `ActivityLogged` event (the spine).
- Rendering an activity timeline — globally or scoped to one record (per feature)
  — with `<KinetixActivityLog>` / `useKinetixActivity`.
- Scoping by team, paginating the feed, or pruning old entries.

## Documentation

For full details, reference `docs/activity.md` (published at https://happones.github.io/kinetix/activity).

## Configuration

```bash
php artisan vendor:publish --tag=kinetix-activity-migrations
php artisan migrate
```

```php
'activity' => [
    'enabled'        => env('KINETIX_ACTIVITY_ENABLED', false),
    // auto = spatie/laravel-activitylog when installed, else native. spatie | native to force.
    'driver'         => env('KINETIX_ACTIVITY_DRIVER', 'auto'),
    'teams'          => env('KINETIX_ACTIVITY_TEAMS', false),
    'per_page'       => env('KINETIX_ACTIVITY_PER_PAGE', 15),
    'retention_days' => env('KINETIX_ACTIVITY_RETENTION_DAYS', 365),
],
```

**Driver**: Kinetix prefers `spatie/laravel-activitylog` when installed (the
standard), falling back to the native `kinetix_activity` table. Both normalize to
the same `ActivityData` DTO, so the UI is identical. With spatie, team-scoping
lives in `properties.team_id` (no schema change) and `kinetix:activity:prune`
delegates to `activitylog:clean`. The `LogsKinetixActivity` trait computes the
`{old, attributes}` diff itself, so the diff is identical across drivers.

---

## Backend Usage

Auto-log a model (causer = `auth()->user()`, updates capture an old→new diff):

```php
use Happones\Kinetix\Activity\Concerns\LogsKinetixActivity;

class Product extends Model
{
    use LogsKinetixActivity;
    // protected function kinetixActivityIgnored(): array { return ['updated_at', ...]; }
}
```

Log manually / read for one record:

```php
use Happones\Kinetix\Activity\KinetixActivity;

KinetixActivity::log('exported', $report, ['format' => 'csv']);
KinetixActivity::log('published', $post, causer: $admin, description: '...');
KinetixActivity::for($product);   // paginated entries for one subject
```

- Each record dispatches `ActivityLogged` (the event spine).
- Native, team-scoped store (`kinetix_activity`); when `activity.teams` is on,
  entries are recorded/read within `currentTeam` (null = global).
- Read endpoint `GET {prefix}/activity` (gated `activity.view`, auto-registers in
  the permission matrix) — filters `subject_type` / `subject_id` / `event` /
  `page`, always paginated, causer eager-loaded.
- Prune with `kinetix:activity:prune` (schedule it; `--days` overrides
  `retention_days`).

---

## Frontend Usage

Self-loading, paginated ("load more") timeline. Scope per record with
`subject-type` + `subject-id`, or omit for the global feed:

```vue
<!-- per feature: a product's history on its show page -->
<KinetixActivityLog subject-type="App\\Models\\Product" :subject-id="product.id" />

<!-- global audit page -->
<KinetixActivityLog />
```

Descriptions are composed from i18n (`activity_event_*`, `activity_by`,
`activity_system`) so "Created by Jane" / "Actualizado por Mo" translate across
en/es/fr/pt. For a custom UI use `useKinetixActivity().load(params)`.
