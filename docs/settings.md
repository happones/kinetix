# Settings

Kinetix Settings is a database-backed, class-based settings panel. You describe a
page's fields with the **Kinetix Forms** engine, and Kinetix handles validation,
defaults, persistence and serialization — so a typed settings screen is a few
lines of PHP. Values are read anywhere through the `KinetixSettings` facade.

---

## Installation

Publish the migration and run it:

```bash
php artisan vendor:publish --tag=kinetix-settings-migrations
php artisan migrate
```

Enable the module in `config/kinetix.php` (opt-in, default off):

```php
'settings' => [
    'enabled'   => env('KINETIX_SETTINGS_ENABLED', false),
    // Scope settings per team (null team = global).
    'teams'     => env('KINETIX_SETTINGS_TEAMS', false),
    // Cache each scope's values; invalidated automatically on write.
    'cache'     => env('KINETIX_SETTINGS_CACHE', true),
    'cache_key' => 'kinetix.settings',
    // Inertia page the bundled controller renders.
    'view'      => env('KINETIX_SETTINGS_VIEW', 'Kinetix/Settings'),
    // Registered SettingsPage classes (or call KinetixSettings::pages()).
    'pages'     => [],
],
```

---

## 1. Defining a settings page

Extend `SettingsPage` and return Kinetix Form components from `schema()`. Every
field is persisted under `{group}.{field}`, so it's addressable later via the
facade. The `group` defaults to the kebab-cased class name (minus `SettingsPage`).

```php
namespace App\Kinetix\Settings;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Settings\SettingsPage;

class GeneralSettingsPage extends SettingsPage
{
    public function schema(): array
    {
        return [
            TextInput::make('site_name')->required(),
            Toggle::make('maintenance_mode'),
        ];
    }
}
```

This page stores `general.site_name` and `general.maintenance_mode`.

Override any of these to customize:

| Method | Default | Purpose |
|---|---|---|
| `group()` | kebab class name | Key prefix for every field |
| `key()` | the group | Route slug for the page |
| `title()` | humanized class name | Display title |
| `navigationIcon()` | `settings` | Lucide icon name |
| `encrypted()` | `[]` | Field names to store **encrypted** (API keys, secrets) |

> Scaffold one with `php artisan kinetix:make-settings-page GeneralSettingsPage`.

---

## 2. Registering pages

Register your pages in a service provider (or list them under
`config('kinetix.settings.pages')`):

```php
use Happones\Kinetix\Settings\KinetixSettings;

public function boot(): void
{
    KinetixSettings::pages([
        \App\Kinetix\Settings\GeneralSettingsPage::class,
    ]);
}
```

---

## 3. Reading & writing settings

The `KinetixSettings` facade is the single entry point. It is team-scoped (when
`settings.teams` is on) and cached, with the cache invalidated on every write:

```php
use Happones\Kinetix\Settings\KinetixSettings;

KinetixSettings::get('general.site_name', 'Acme');   // value or default
KinetixSettings::set('general.maintenance_mode', true);
KinetixSettings::set('mail.api_key', $secret, encrypted: true);
KinetixSettings::forget('general.site_name');
KinetixSettings::all();                               // key => value for the scope
```

Values keep their type (stored as JSON) — booleans, arrays and scalars round-trip
faithfully. Encrypted values are transparently decrypted on read and never stored
in clear.

---

## 4. Authorization & teams

- Management endpoints are gated by the **`settings.manage`** ability. With the
  [Roles & Permissions](/permissions) module enabled, grant it to a role; the
  `settings` feature auto-registers in the permission matrix when this module is on.
- When `settings.teams` is `true`, values are scoped to the user's `currentTeam`
  (null team = global) — the same bridge the Permissions module uses.

---

## 5. Endpoints

Team-aware, mounted under the Kinetix route prefix, gated by `settings.manage`:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/settings` | Render the first page |
| `GET` | `{prefix}/settings/{page}` | Render a specific page |
| `PUT` | `{prefix}/settings/{page}` | Validate + persist the page's form (JSON) |

---

## 6. Frontend (Vue / Inertia)

The controller renders the page named by `settings.view` (default
`Kinetix/Settings`) with `{ pages, active }`. Mount `<KinetixSettingsForm>` with
the `active` page — it reuses `<KinetixForm>` and posts to the settings endpoint:

```vue
<script setup lang="ts">
import KinetixSettingsForm from '@/components/kinetix/KinetixSettingsForm.vue'
import type { KinetixSettingsPageData } from '@/types'

defineProps<{
  pages: { key: string; title: string; icon: string }[]
  active: KinetixSettingsPageData
}>()
</script>

<template>
  <KinetixSettingsForm :page="active" />
</template>
```

Need a custom flow? Use the `useKinetixSettings()` composable directly —
`load(pageKey)`, `save(pageKey, values)`, plus reactive `loading` / `saving` flags.

---

## 7. Account settings vs. application settings

This module manages **application settings** — admin-owned configuration that
changes how the app behaves (site name, maintenance mode, API keys), stored
globally or per team and gated by `settings.manage`.

That's a different concern from the starter kit's **account settings** section
(Profile, Security, Teams, Appearance), which is each user managing *their own*
account. The two are complementary, not overlapping — and the starter kit already
hosts a Kinetix module inside that section: **Roles & Permissions** is just a tab
rendering `<KinetixRoleManager>`.

### Adding application settings as a settings tab

Drop it in exactly like Roles & Permissions — a self-loading component, no host
controller. Because `<KinetixSettingsForm page-key="…">` fetches its own DTO from
the (already-registered) settings endpoint, the host only adds a page and a nav
entry:

```php
// routes/settings.php — a plain Inertia tab page
Route::inertia('settings/application', 'settings/ApplicationSettings')
    ->name('settings.application');
```

```vue
<!-- resources/js/pages/settings/ApplicationSettings.vue -->
<script setup lang="ts">
import KinetixCan from '@/components/kinetix/KinetixCan.vue'
import KinetixSettingsForm from '@/components/kinetix/KinetixSettingsForm.vue'
</script>

<template>
  <KinetixCan permission="settings.manage">
    <KinetixSettingsForm page-key="general" />
    <template #denied>…</template>
  </KinetixCan>
</template>
```

Then add a nav item to the settings layout, shown only to admins (mirroring how
the Roles tab is gated by `can('roles.manage')`):

```ts
...(can('settings.manage')
  ? [{ title: 'Application', href: '/settings/application' }]
  : []),
```

> Prefer a standalone, Kinetix-owned settings section instead? Skip the host page
> and point users at `GET {prefix}/settings` — the bundled controller renders the
> `settings.view` page with the full page list. Both paths use the same JSON
> `update` endpoint.
