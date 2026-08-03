---
name: kinetix-settings
description: "Database-backed, class-based settings pages built on the Kinetix Forms engine. Activates when defining a SettingsPage, reading/writing app or team settings via KinetixSettings, persisting a settings form, or rendering the settings UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Settings Development

## When to Apply

Activate this skill when:
- Building a settings panel: defining a `SettingsPage` with a Forms `schema()`.
- Reading or writing persisted config via `KinetixSettings::get()/set()/forget()/all()`.
- Persisting a settings form (the `settings.*` endpoints) or rendering the UI
  (`<KinetixSettingsForm>` / `useKinetixSettings`).
- Storing secrets (encrypted settings) or scoping settings per team.

## Documentation

For full details, reference `docs/settings.md` (published at https://happones.github.io/kinetix/settings).

## Configuration

Publish + migrate, then enable in `config/kinetix.php` (opt-in, default off):

```bash
php artisan vendor:publish --tag=kinetix-settings-migrations
php artisan migrate
```

```php
'settings' => [
    'enabled'   => env('KINETIX_SETTINGS_ENABLED', false),
    'teams'     => env('KINETIX_SETTINGS_TEAMS', false),
    'cache'     => env('KINETIX_SETTINGS_CACHE', true),
    'cache_key' => 'kinetix.settings',
    'view'      => env('KINETIX_SETTINGS_VIEW', 'Kinetix/Settings'),
    'pages'     => [], // SettingsPage classes (or KinetixSettings::pages([...]))
],
```

---

## Backend Usage

Define a page (each field persists under `{group}.{field}`):

```php
use Happones\Kinetix\Forms\Components\{TextInput, Toggle};
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

    // Optional: store these field names encrypted (API keys, secrets).
    public function encrypted(): array { return []; }
}
```

Register it (provider or `config('kinetix.settings.pages')`) and read/write:

```php
use Happones\Kinetix\Settings\KinetixSettings;

KinetixSettings::pages([GeneralSettingsPage::class]);

KinetixSettings::get('general.site_name', 'Acme');     // value or default
KinetixSettings::set('general.maintenance_mode', true); // type preserved (JSON)
KinetixSettings::set('mail.api_key', $secret, encrypted: true);
KinetixSettings::forget('general.site_name');
```

- Values keep their type (bool/array/scalar). Encrypted values decrypt on read,
  never stored in clear.
- Scope: `settings.teams=true` → scoped to `currentTeam` (null = global), cached
  per scope, cache invalidated on write.
- Endpoints (team-aware, gated by `settings.manage`): `GET {prefix}/settings`,
  `GET {prefix}/settings/{page}`, `PUT {prefix}/settings/{page}` (validate + save,
  JSON). The `settings` feature auto-registers with the permission matrix when the
  Permissions module is on.
- Generator: `php artisan kinetix:make-settings-page GeneralSettingsPage`.

---

## Frontend Usage

The controller renders `config('kinetix.settings.view')` (default
`Kinetix/Settings`) with `{ pages, active }`. Mount `<KinetixSettingsForm>` (reuses
`<KinetixForm>`, posts to the settings endpoint):

```vue
<script setup lang="ts">
import KinetixSettingsForm from '@/components/kinetix/KinetixSettingsForm.vue'
import type { KinetixSettingsPageData } from '@/types/kinetix'

defineProps<{ active: KinetixSettingsPageData }>()
</script>

<template>
  <KinetixSettingsForm :page="active" />
</template>
```

For a custom flow use `useKinetixSettings()` — `save(pageKey, values)` + a
reactive `saving` flag.

## UUID / ULID Host Models

This feature's migration builds `team_id` with
`Happones\Kinetix\Support\HostKeys`, which types each column after YOUR model
at migrate time (`HasUlids` -> ulid, `HasUuids` -> uuid, string `$keyType` ->
string, else bigint). Pin `kinetix.key_types.user|team` when detection cannot
see the setup; morph ids follow `kinetix.key_types.morph` (default bigint) —
set it when the referenced models use UUIDs/ULIDs. Apps migrated on an older
Kinetix have bigint columns on disk and need their own ALTER migration. Full
recipe: the `kinetix-boost` skill, section "UUID / ULID Host Models".
