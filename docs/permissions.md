# Roles & Permissions

Kinetix provides a feature-scoped roles and permissions system built on top of the popular [`spatie/laravel-permission`](https://github.com/spatie/laravel-permission) package.

Enforcement of authorization still flows natively through Laravel's standard `Gate` (which Kinetix resources and actions consume by default). This module adds a centralized permission registry, database synchronization, a super-admin bypass, and automatic tenant/team bridging.

---

## Requirements

To use this feature, you must have `spatie/laravel-permission` version 6 or superior installed:

```bash
composer require spatie/laravel-permission
```

---

## Configuration

Enable permissions in your `config/kinetix.php` file:

```php
'permissions' => [
    // Enable the permissions registry & super-admin gate checks (opt-in)
    'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', false),

    // Enable multi-tenant/team support for permissions
    'teams'            => env('KINETIX_PERMISSIONS_TEAMS', false),

    // Users with this role will bypass all Gate authorization checks
    'super_admin_role' => env('KINETIX_SUPER_ADMIN_ROLE', 'super-admin'),

    // The guard permissions are registered under
    'guard'            => env('KINETIX_PERMISSIONS_GUARD', 'web'),
],
```

---

## 1. Declaring Permissions

You can register permissions in two ways: automatically via Kinetix Resources or explicitly via the `KinetixPermissions` facade.

### A. Resource Permissions (Automatic CRUD)

To associate permissions with a Kinetix Resource, implement the `permissionFeature` method on your Resource class:

```php
namespace App\Kinetix\Resources;

use Happones\Kinetix\Resources\Resource;

class PostResource extends Resource
{
    public static function permissionFeature(): ?string
    {
        return 'posts';
    }
}
```

By default, defining a feature name auto-registers the 5 standard CRUD abilities:
* `posts.viewAny` (View list)
* `posts.view` (View details)
* `posts.create` (Create)
* `posts.update` (Update)
* `posts.delete` (Delete)

#### Customizing Resource Abilities
If a resource requires custom abilities on top of CRUD, override the `registerPermissions` method:

```php
use Happones\Kinetix\Permissions\PermissionRegistry;

public static function registerPermissions(PermissionRegistry $registry): void
{
    $registry->feature('posts')
        ->crud()
        ->softDeletes() // Adds posts.restore & posts.forceDelete
        ->ability('publish', 'Publish posts'); // Adds posts.publish
}
```

### B. Explicit Feature Permissions

For features, modules, or settings that don't belong to a Resource, register them in your `AppServiceProvider` (or a dedicated service provider) using the `KinetixPermissions` facade:

```php
namespace App\Providers;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        KinetixPermissions::feature('billing')
            ->label('Billing & Subscriptions')
            ->abilities([
                'manage' => 'Manage subscription plans',
                'view-invoices' => 'View invoices',
            ]);
    }
}
```

---

## 2. Syncing Permissions

To write the registered permissions into the database, run the sync command:

```bash
php artisan kinetix:permissions:sync
```

### Pruning Obsolete Permissions
If you remove features or abilities from your codebase, you can automatically delete their records from the database using the `--prune` option:

```bash
php artisan kinetix:permissions:sync --prune
```

---

## 3. Super Admin Role

When `permissions.enabled` is `true`, Kinetix automatically registers a `Gate::before` callback:

```php
Gate::before(function ($user, string $ability) {
    if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
        return true;
    }
});
```

This bypasses all gate checks for any user carrying the `super-admin` role (or whichever role name is configured under `'super_admin_role'`).

---

## 4. Multi-Tenant (Teams) Support

If your application scopes roles and permissions per team:

1. Enable the `teams` setting in `config/kinetix.php`:
   ```php
   'permissions' => [
       'teams' => true,
   ],
   ```
2. Apply the `kinetix.permissions.team` middleware to your routes or global middleware stack.

This ensures roles and permissions are resolved only within the context of the active team.

### Trait collision with the starter-kit's `HasTeams`

`spatie/laravel-permission` v8 (the Laravel 13 line) ships a `teams()` method on
`HasRoles`. So does the starter-kit's `HasTeams` trait. When your `User` uses
both, PHP aborts at boot with a fatal trait-method collision:

```
Symfony\Component\ErrorHandler\Error\FatalError
Trait method App\Concerns\HasTeams::teams has not been applied as
App\Models\User::teams, because of collision with
Spatie\Permission\Traits\HasRoles::teams
```

The two `teams()` mean different things:

| Trait | What `teams()` returns |
|---|---|
| `HasTeams` (starter kit) | The user's **team memberships** — `belongsToMany(Team, 'team_members')`. Much of `HasTeams` calls `$this->teams()` (`belongsToTeam`, `personalTeam`, `toUserTeams`, `fallbackTeam`, …), and so does your app/Inertia. |
| `HasRoles` (spatie v8) | A **convenience** relation: the teams the user has *roles* on (`morphToMany` over `model_has_roles`). Spatie never calls it internally — team scoping runs off `getPermissionsTeamId()` in the `PermissionRegistrar`, not this relation. |

So the starter-kit `teams()` **must** win. Resolve it in the `User` model with
PHP's trait conflict resolution — `insteadof` alone is enough:

```php
use App\Concerns\HasTeams;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles, HasTeams {
        HasTeams::teams insteadof HasRoles;   // keep the starter-kit relation as User::teams()
    }

    // ...
}
```

If you also want spatie's relation, keep it under an alias (optional — most apps
never need it):

```php
use HasRoles, HasTeams {
    HasTeams::teams insteadof HasRoles;
    HasRoles::teams as roleTeams;             // → teams the user has roles on
}
```

This is safe with Kinetix: team-scoped permissions are bridged through the user's
`currentTeam` and the `PermissionRegistrar` (the `kinetix.permissions.team`
middleware, `SetPermissionsTeam`) — **not** through `$user->teams()`. Discarding
or aliasing spatie's `teams()` does not affect how roles or permissions resolve
per team.

> `insteadof` names the trait method to **keep**; the other is excluded. `as` is
> only needed if you want the excluded method to stay callable under a new name —
> it does not by itself resolve the collision.

---

## 5. Frontend Authorization (Vue / Inertia)

Kinetix **automatically shares** the authenticated user's resolved permissions and
roles via the `kinetix_permissions` Inertia prop — you do **not** need to edit your
`HandleInertiaRequests`. Gate your UI with the shipped helpers, using the same
`{feature}.{ability}` keys the backend enforces. All checks are reactive (they
update when Inertia replaces the page props, e.g. after a role change).

### 5.1 `useKinetixCan` composable

```vue
<script setup lang="ts">
import { useKinetixCan } from '@/composables/useKinetixCan'

const { can, canAny, canAll, hasRole } = useKinetixCan()
</script>

<template>
  <button v-if="can('posts.create')">Create Post</button>
  <nav v-if="canAny(['posts.viewAny', 'users.viewAny'])">…</nav>
  <AdminPanel v-if="hasRole('admin')" />
</template>
```

### 5.2 `<KinetixCan>` component

Best when you want a fallback (`#denied`) or role checks:

```vue
<KinetixCan permission="posts.update">
  <EditButton />
  <template #denied><span class="text-muted-foreground">Read only</span></template>
</KinetixCan>

<!-- any-of by default; pass `require-all` for all-of -->
<KinetixCan :permission="['posts.create', 'posts.update']" require-all>…</KinetixCan>

<KinetixCan role="admin">…</KinetixCan>
```

### 5.3 `v-can` directive

Register the plugin once in your entry file, then use `v-can` for lightweight
show/hide (an element with a failing check is hidden):

```typescript
import { KinetixPermissions } from '@/plugins/kinetixPermissions'

createInertiaApp({
  // ...
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .use(KinetixPermissions)
      .mount(el)
  },
})
```

```html
<button v-can="'posts.create'">Create</button>
<a v-can="['posts.update', 'posts.view']">Edit</a>  <!-- any-of -->
```

> Prefer `<KinetixCan>` when you need a fallback slot or role checks; `v-can` is a
> minimal `display` toggle.

---

## 6. Role Management UI

Drop in `<KinetixRoleManager>` to let admins create roles and assign permissions —
grouped by feature, with search and per-feature select-all. Gate it behind the
built-in `roles.manage` ability:

```vue
<KinetixCan permission="roles.manage">
  <KinetixRoleManager />
</KinetixCan>
```

It talks to the built-in endpoints registered under your Kinetix route prefix
(team-aware), all gated by `roles.manage` (super-admin bypasses):

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/permissions/features` | Permission catalog grouped by feature |
| `GET` | `{prefix}/permissions/roles` | List roles with their permissions |
| `POST` | `{prefix}/permissions/roles` | Create a role |
| `PUT` | `{prefix}/permissions/roles/{role}` | Rename / sync a role's permissions |
| `DELETE` | `{prefix}/permissions/roles/{role}` | Delete a role |

Need a custom flow? Compose `KinetixPermissionMatrix` (the feature-grouped grid,
`v-model` of permission keys) with `useKinetixRoles` (the CRUD composable).

### Seeding starter roles

`KinetixRolesSeeder` layers a classic RBAC preset on top of the registry —
`super-admin` (bypasses every gate), `admin` (all permissions), `editor`
(everything except `delete`/`forceDelete`) and `viewer` (read-only):

```bash
php artisan kinetix:permissions:sync   # materialize permissions first
php artisan db:seed --class="Happones\\Kinetix\\Permissions\\KinetixRolesSeeder"
```
