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
`save(pageKey, values)` plus a reactive `saving` flag.
