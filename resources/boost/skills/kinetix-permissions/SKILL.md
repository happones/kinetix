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
    'protected_roles'  => null, // null = protect the super_admin_role; or ['super-admin', 'owner']
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

1. `config/kinetix.php` → `'permissions' => ['teams' => true]` — or leave it `null` and set the global `'teams' => true`, which every module's flag inherits (v0.82.0).
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

### Role-endpoint guardrails (v0.104.0)

The `{prefix}/permissions/roles` endpoints stop a `roles.manage` holder from
escalating past their own level — **all bypassed for a super-admin**:

1. **Allowlist** — `permissions.*` is validated against the registry, so unknown
   keys are rejected (422).
2. **Grant only what you hold** — a manager can only assign permissions they
   themselves have (403 otherwise). Behavior change: previously any `roles.manage`
   holder could grant anything. Give role admins the seeded `admin` role (or
   super-admin) to manage the full catalog.
3. **Protected roles & self-lockout** — roles in `permissions.protected_roles`
   (default: the super-admin role) can't be created/renamed/edited/deleted here,
   and any change that would revoke the actor's own `roles.manage` is rolled back
   (403). This runs inside a DB transaction.

### Super-admin parity on the frontend

A super-admin holds the *role*, not the permissions. The `kinetix_permissions`
prop carries an `isSuperAdmin` flag and `useKinetixCan().can()` / `<KinetixCan>`
honor it, so a super-admin is **not** shown a permission-gated UI as denied.
`useKinetixCan` also exposes `isSuperAdmin`.

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

Drop in `<KinetixRoleManager>` (grouped checkbox lists) or `<KinetixRoleMatrix>` (role cards with member counts + a module × ability grid editor — canonical CRUD columns, custom abilities appended, click a module to toggle its row), both gated behind `roles.manage`. They use the endpoints
`{prefix}/permissions/{features,roles}` (CRUD, gated by `roles.manage`). For custom
flows compose `KinetixPermissionMatrix` (`v-model` of permission keys) with
`useKinetixRoles`. Seed starter roles with `KinetixRolesSeeder`.

```vue
<KinetixCan permission="roles.manage">
  <KinetixRoleManager />
</KinetixCan>
```
