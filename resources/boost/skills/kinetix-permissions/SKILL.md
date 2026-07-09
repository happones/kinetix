---
name: kinetix-permissions
description: "Handles role and permission management, configuration, database synchronization, and frontend authorization checking using spatie/laravel-permission. Activates when configuring permissions, writing gates, running permissions sync, or enforcing front-end access check helper."
license: MIT
metadata:
  author: happones
---

# Kinetix Permissions Development

## When to Apply

Activate this skill when:
- Registering feature or resource permissions using the `KinetixPermissions` facade.
- Enforcing resource-level permissions by overriding `permissionFeature()` or `registerPermissions()`.
- Running the `php artisan kinetix:permissions:sync` command to synchronize registry definitions to Spatie permissions.
- Gating Vue UI with the shipped helpers (`useKinetixCan`, `<KinetixCan>`, `v-can`).
- Managing roles via `<KinetixRoleManager>` / `KinetixPermissionMatrix` or the role endpoints.
- Implementing multi-tenant/team scoped permissions with the `kinetix.permissions.team` middleware.

## Documentation

For full details, reference `docs/permissions.md` (published at https://happones.github.io/kinetix/permissions).

## Configuration

Ensure the permissions module is enabled in `config/kinetix.php` (opt-in, default off):

```php
'permissions' => [
    'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', false),
    'teams'            => env('KINETIX_PERMISSIONS_TEAMS', false),
    'super_admin_role' => env('KINETIX_SUPER_ADMIN_ROLE', 'super-admin'),
    'guard'            => env('KINETIX_PERMISSIONS_GUARD', 'web'),
],
```

### Teams: `HasTeams` × `HasRoles` trait collision

spatie v8 (Laravel 13) ships a `teams()` on `HasRoles`, so does the starter-kit's
`HasTeams` — using both on the `User` fatals at boot:

```
Trait method App\Concerns\HasTeams::teams has not been applied as
App\Models\User::teams, because of collision with Spatie\Permission\Traits\HasRoles::teams
```

The starter-kit `teams()` (real team membership; `HasTeams` and your app call it)
must win; spatie's is just a convenience relation it never uses internally
(scoping runs off `getPermissionsTeamId()`). `insteadof` alone resolves it:

```php
use HasRoles, HasTeams {
    HasTeams::teams insteadof HasRoles;   // keep starter-kit relation as User::teams()
    // HasRoles::teams as roleTeams;      // optional: spatie's "teams I have roles on"
}
```

Safe with Kinetix — team-scoped permissions bridge through `currentTeam` +
`PermissionRegistrar` (`kinetix.permissions.team` middleware), not `$user->teams()`.
See `docs/permissions.md` §4.

### Teams: the four required steps

Enabling `kinetix.permissions.teams` alone does nothing — spatie ignores the
team id unless its own flag is on (Kinetix logs a boot warning on the mismatch):

1. `config/kinetix.php` → `'permissions' => ['teams' => true]`.
2. `config/permission.php` → `'teams' => true`.
3. `php artisan vendor:publish --tag=kinetix-permission-team-migrations && php artisan migrate` — Kinetix's **hybrid** teams migration (nullable `team_id` outside the PK → roles can be team-scoped *or* global).
4. Append `kinetix.permissions.team` to the host's `web` middleware group (Kinetix only auto-applies it to its own routes; without this, `hasRole()`/`can()` in app routes have no team context).

### Platform super-admin (teamless)

With spatie teams on, `hasRole()` is team-scoped. The Kinetix `Gate::before`
additionally honors a **teamless** assignment — assign with a NULL team id for
a platform-wide super-admin:

```php
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
$user->assignRole('super-admin');   // bypasses gates inside every team
```

A super-admin assigned inside a team keeps the bypass only in that team.

### Sync on deploy and in tests

`kinetix:permissions:sync` must run **after migrations on every deploy** and in
test setup (`beforeEach`/`setUp`) — an empty `permissions` table makes roles
appear to work while carrying no permissions.

---

## Backend Usage

### 1. Resource CRUD Permissions
Enable CRUD permissions on a Kinetix Resource by returning the feature name:

```php
namespace App\Kinetix\Resources;

use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Permissions\PermissionRegistry;

class PostResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'posts';
    }

    public static function registerPermissions(PermissionRegistry $registry): void
    {
        $registry->feature('posts')
            ->crud()
            ->softDeletes()
            ->ability('publish', 'Publish posts');
    }
}
```

### 2. Custom Explicit Feature Permissions
For standalone modules or custom sections, register them in your `AppServiceProvider` via the facade:

```php
use Happones\Kinetix\Permissions\KinetixPermissions;

KinetixPermissions::feature('billing')
    ->label('Billing Management')
    ->abilities([
        'manage' => 'Manage subscription plans',
        'view-invoices' => 'View invoices',
    ]);
```

### 3. Syncing Permissions to Spatie
Run the sync command to persist permissions to your database:

```bash
# Standard sync
php artisan kinetix:permissions:sync

# Sync and prune obsolete permissions
php artisan kinetix:permissions:sync --prune
```

---

## Frontend Usage

Kinetix shares the user's resolved permissions/roles via the `kinetix_permissions`
Inertia prop automatically — **do not** hand-edit `HandleInertiaRequests`. Gate UI
with the shipped helpers (all reactive, keyed by `{feature}.{ability}`).

### 1. `useKinetixCan` composable

```vue
<script setup lang="ts">
import { useKinetixCan } from '@/composables/useKinetixCan'

const { can, canAny, canAll, hasRole } = useKinetixCan()
</script>

<template>
  <button v-if="can('posts.create')">Create Post</button>
</template>
```

### 2. `<KinetixCan>` component (supports a `#denied` slot, `require-all`, `role`)

```vue
<KinetixCan permission="posts.update">
  <EditButton />
  <template #denied>Read only</template>
</KinetixCan>
```

### 3. `v-can` directive

Register once: `app.use(KinetixPermissions)` (from `@/plugins/kinetixPermissions`), then:

```html
<button v-can="'posts.create'">Create</button>
```

### 4. Role management UI

Drop in `<KinetixRoleManager>` (gate it behind `roles.manage`). It uses the endpoints
`{prefix}/permissions/{features,roles}` (CRUD, gated by `roles.manage`). For custom
flows compose `KinetixPermissionMatrix` (`v-model` of permission keys) with
`useKinetixRoles`. Seed starter roles with `KinetixRolesSeeder`.

```vue
<KinetixCan permission="roles.manage">
  <KinetixRoleManager />
</KinetixCan>
```
