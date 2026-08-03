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

## Common integration mistakes

> **Diagnose first.** `php artisan kinetix:doctor` reports every silent
> misconfiguration in one shot (prefix, team scoping, teamless roles,
> `attach_member`, config closures, published-file drift, duplicated i18n
> bundles) and exits non-zero on errors.

Check these **first** — they are what actually breaks integrations, and each one
fails silently:

1. **Writing your own controller under the wrong prefix.** Kinetix registers its
   endpoints under `{current_team}/{kinetix.route_prefix}/permissions/…` (e.g.
   `/acme/_kinetix/permissions/roles`) and the components call those URLs
   themselves. A controller of yours at `{current_team}/roles` is never invoked.
   The app registers only the *Inertia page* route. Verify with
   `php artisan kinetix:routes` before writing any endpoint.
2. **Redefining a `kinetix_*` Inertia prop.** `HandleInertiaRequests::share()` is
   merged **over** the package's shared props, so returning your own
   `kinetix_permissions` key silently replaces it and every `can()` turns false.
   Share your data under your own key. (Kinetix logs a warning in `local` when it
   detects the override.)
3. **Assuming the owner bypass exists.** Team ownership is not a role, so no role
   grants it. Set `permissions.owner_bypass => true` (uses the host's
   `$user->ownsTeam($team)`) or pass a callback as a callable array
   (`[OwnerBypass::class, 'check']`). Without it a team owner with no role holds
   **no** permissions. Do **not** hand-write a blanket
   `Gate::before(fn ($user) => $user->ownsTeam(...) ? true : null)`: it also
   short-circuits model policies, so the owner of team A passes `update` on team
   B's records. Kinetix's bypass only grants registry abilities.
4. **Seeding roles without team context.** Under team scoping a role created with
   no team id is *global*: visible in every team, editable by super-admins only.
   Pin the team first (`setPermissionsTeamId($team->id)`).
   `kinetix:permissions:sync` lists teamless roles that aren't protected.
5. **Forgetting the sync.** Enforcement reads the DB; an unsynced catalog makes
   roles look fine while granting nothing. Run it on deploy *and* in test setup.

6. **Writing a config callback as a closure.** `owner_bypass` (and Membership's
   `assignable_roles` / `attach_member` / `detach_member`) as `fn (...) => ...`
   makes `php artisan config:cache` abort ("value at … is non-serializable"), so
   the app can't deploy. Use `[OwnerBypass::class, 'check']` or an invokable
   class-string — both are resolved through the container.

## Configuration

Ensure the permissions module is enabled in `config/kinetix.php` (opt-in, default off):

```php
'permissions' => [
    'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', false),
    'teams'            => env('KINETIX_PERMISSIONS_TEAMS', false),
    'super_admin_role' => env('KINETIX_SUPER_ADMIN_ROLE', 'super-admin'),
    // Owner-can-do-everything bypass: true (uses $user->ownsTeam($team)),
    // [Class::class, 'method'], an invokable class-string, or null.
    // Grants only REGISTRY abilities — model policies still run.
    'owner_bypass'     => env('KINETIX_PERMISSIONS_OWNER_BYPASS'),
    'guard'            => env('KINETIX_PERMISSIONS_GUARD', 'web'),
    'protected_roles'  => null, // null = protect the super_admin_role; or ['super-admin', 'owner']
],
```

### Team owners (`owner_bypass`)

Ownership lives in the host's team schema, not in `model_has_roles` — so it can
never be granted by a role and needs its own `Gate::before`. Kinetix registers it
when `permissions.owner_bypass` is set, resolving the team from the
`{current_team}` segment (falling back to `currentTeam`) and memoizing the verdict
per user × team. The `kinetix_permissions` prop picks these dynamic grants up, so
the SPA matches the server without the owner holding a permission row.

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
`{prefix}/permissions/{features,roles}` (CRUD, gated by `roles.manage`) — where
`{prefix}` is `{current_team}/_kinetix` with teams on, **never a path of your
own**; `php artisan kinetix:routes` prints the resolved URIs. For custom
flows compose `KinetixPermissionMatrix` (`v-model` of permission keys) with
`useKinetixRoles`. Seed starter roles with `KinetixRolesSeeder`.

```vue
<KinetixCan permission="roles.manage">
  <KinetixRoleManager />
</KinetixCan>
```

## UUID / ULID Host Models

`kinetix-permission-team-migrations` adds `team_id` columns (typed by
`Happones\Kinetix\Support\HostKeys` after the app's Team model) AND real
foreign keys to spatie/laravel-permission's pivot tables. On a UUID/ULID app,
first apply spatie's own UUID guidance to the permission tables (their
`model_morph_key` config + retyped pivots); the `team_id` type then follows
detection or a pinned `kinetix.key_types.team`. General recipe: the
`kinetix-boost` skill, section "UUID / ULID Host Models".
