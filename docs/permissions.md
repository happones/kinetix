# Roles & Permissions

Kinetix provides a feature-scoped roles and permissions system built on top of the popular [`spatie/laravel-permission`](https://github.com/spatie/laravel-permission) package.

Enforcement of authorization still flows natively through Laravel's standard `Gate` (which Kinetix resources and actions consume by default). This module adds a centralized permission registry, database synchronization, a super-admin bypass, and automatic tenant/team bridging.

::: danger Read this first — where the endpoints live
Kinetix registers its **own** endpoints and the published components call them
themselves. With teams on they live under:

```
{current_team}/{kinetix.route_prefix}/permissions/...   →  e.g. /acme/_kinetix/permissions/roles
```

So: **do not write your own controller under `{current_team}/roles` (or any other
path) expecting `<KinetixRoleManager>` / `<KinetixRoleMatrix>` to hit it — it
never will.** You only register the *Inertia page* route; the data flows through
the built-in endpoints. To see exactly what the frontend talks to:

```bash
php artisan kinetix:routes            # every Kinetix endpoint, resolved URI + middleware
php artisan kinetix:routes permissions   # filter
```
:::

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

    // Grant a team's OWNER every ability (see §3). true | closure | invokable
    // class-string | null (off)
    'owner_bypass'     => env('KINETIX_PERMISSIONS_OWNER_BYPASS'),

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

#### How Resources Are Registered (Auto-Discovery)

Resources placed in `app/Kinetix/Resources` are **auto-discovered** — you do not
list them anywhere. Kinetix scans that directory and derives permissions from
each Resource's `permissionFeature()`; a Resource that returns `null` (the
default) is skipped, so discovery never over-grants. This is controlled by
`config/kinetix.php`:

```php
'permissions' => [
    // Set to null to disable discovery and register resources manually.
    'discover_path'      => app_path('Kinetix/Resources'),
    'discover_namespace' => 'App\\Kinetix\\Resources',
],
```

To register a Resource that lives outside the discovered directory (or when you
disable discovery), register it explicitly from a service provider:

```php
use Happones\Kinetix\Permissions\KinetixPermissions;

KinetixPermissions::resource(\App\Other\PostResource::class);

// Or point discovery at an additional directory:
KinetixPermissions::discoverResources(
    in: app_path('Domain/Resources'),
    for: 'App\\Domain\\Resources',
);
```

Manual and discovered registrations merge without duplicates.

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

### C. Mapping real-world permission shapes

Most catalogs mix three shapes. Each is one line to declare, and the role
editor renders each the right way — canonical abilities as fixed matrix
columns, everything else inside its module's row:

```php
// 1. Classic CRUD module → the matrix columns (View list / View / Create / Edit / Delete).
KinetixPermissions::feature('products')->crud();

// 2. Access-only module (dashboards, report sections — no per-record CRUD):
//    a single `reports.access` ability, rendered as the matrix's FIRST column.
KinetixPermissions::feature('reports')->access();

// 3. Custom abilities INSIDE a module (field-level or action-level):
//    never a column — they render with their full label in the module's
//    expandable row (the `n/m` chip next to the module name).
KinetixPermissions::feature('employees')->group('HR')->crud()
    ->ability('viewSalary', __('perms.view_salary'))
    ->ability('export', __('perms.export_payroll'));
```

The header vocabulary is FIXED (`access` + the CRUD lifecycle): no matter how
many modules or custom abilities you declare, columns never multiply.
`->group('HR')` optionally clusters modules into titled sections in both role
UIs.

#### Enforcing a field-level permission (`->can()`)

Declaring `employees.viewSalary` gates nothing by itself — attach it to the
schema components that expose the field. `->can()` is evaluated **server-side
at serialization**: a denied field is stripped from the form schema, its
validation rules, the submitted state (a smuggled value never reaches the
model), the infolist, and the table's columns *and row payloads* — the salary
never leaves the server:

```php
// Form (create/edit)
TextInput::make('salary')->can('employees.viewSalary'),

// Infolist (show)
TextEntry::make('salary')->can('employees.viewSalary'),

// Table (index — header, cells, sorting and inline edits all gated)
TextColumn::make('salary')->can('employees.viewSalary'),
```

> `->can()` differs from `->authorize()`: `authorize()` evaluates a
> record-bound *policy* ability (and defers when no record is available yet),
> while `can()` checks a *permission key* against the authenticated user with
> no subject and never defers. For field-level permissions, use `can()`.

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

### Run it on every deploy — and in your tests

The permission catalog lives in code (your service provider), but enforcement
reads the **database**. If the `permissions` table is stale or empty, roles
appear to "create fine" but carry no permissions — a confusing failure mode.
Make the sync part of both lifecycles:

**Deploy** — after migrations:

```bash
php artisan migrate --force
php artisan kinetix:permissions:sync
```

**Tests** — permissions start empty on a fresh test database, so sync before
each test that touches roles (Pest example):

```php
beforeEach(function () {
    $this->artisan('kinetix:permissions:sync');
});
```

or in a PHPUnit `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->artisan('kinetix:permissions:sync');
}
```

---

## 3. Gate bypasses (super admin & team owners)

### Super admin role

When `permissions.enabled` is `true`, Kinetix automatically registers a `Gate::before` callback:

```php
Gate::before(function ($user, string $ability) {
    if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
        return true;
    }
});
```

This bypasses all gate checks for any user carrying the `super-admin` role (or whichever role name is configured under `'super_admin_role'`).

> **Frontend parity.** A super-admin holds the *role*, not the individual
> permissions — so a naive `can()` would return `false` for everything and hide
> UI the server actually authorizes. Kinetix ships an `isSuperAdmin` flag on the
> `kinetix_permissions` Inertia prop, and `useKinetixCan().can()` / `<KinetixCan>`
> honor it: a super-admin sees every gated element, mirroring the server bypass.

### Platform super-admin with teams

When spatie team scoping is active (see §4), `hasRole()` becomes **team-scoped** —
a super-admin assigned inside team A would lose the bypass inside team B. Kinetix
handles this: the `Gate::before` also honors a **teamless** assignment (role
attached with team `NULL`), so a platform-wide super-admin is one assigned with
no team context:

```php
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(null);
$user->assignRole('super-admin');   // global — bypasses gates inside every team
```

A super-admin assigned *inside* a team keeps the bypass only in that team.
Teamless assignments require the hybrid teams migration below (spatie's stock
teams migration puts the team key in the pivot's primary key, which cannot be
`NULL`).

### Team owners

In a teams app, **"the owner can do everything"** is the most common rule — and
it is *not* a role: ownership lives in your own team schema (`teams.user_id`, a
pivot flag, …), never in `model_has_roles`. So no role assignment grants it and
no permission sync creates it. It needs its own bypass, which Kinetix turns into
one config line:

```php
// config/kinetix.php → permissions
'owner_bypass' => true,   // uses the host's $user->ownsTeam($team)
```

With `true`, Kinetix resolves the team the request is scoped to (the
`{current_team}` segment via `SetPermissionsTeam`, falling back to the user's
`currentTeam`) and calls the host's `ownsTeam()` — the starter-kit / Jetstream
`HasTeams` convention. Need a different rule? Pass a callback receiving the user
and the resolved team — as a **callable array** or an invokable class-string, not
a closure:

```php
'owner_bypass' => [\App\Kinetix\OwnerBypass::class, 'check'],   // check($user, $team): bool
'owner_bypass' => \App\Kinetix\OwnerBypass::class,              // __invoke($user, $team): bool
```

::: danger A closure here makes the app undeployable
A `Closure` in a config file breaks `php artisan config:cache`:

```
Your configuration files could not be serialized because the value at
"kinetix.permissions.owner_bypass" is non-serializable.
```

Both serializable forms above are resolved through the container (an instance
method gets a container-resolved instance, so constructor injection works). The
same applies to Membership's `attach_member`, `detach_member` and
`assignable_roles`.
:::

The verdict is memoized per user × team (`Gate::before` fires on *every* check),
and it is picked up by the frontend automatically: the `kinetix_permissions`
prop includes registered abilities the Gate grants dynamically, so an owner's UI
matches what the server authorizes without them holding a single permission row.

::: warning A naive owner `Gate::before` is a cross-tenant hole
This is the trap to know about if you write the bypass yourself:

```php
// ❌ grants EVERY ability, including model policies
Gate::before(fn ($user) => $user->ownsTeam(currentTeam()) ? true : null);
```

`Gate::before` runs ahead of **all** authorization, policies included. So
`Gate::authorize('update', $postFromAnotherTeam)` also returns true, and the
owner of team A can edit team B's records — the tenancy boundary is gone.

Kinetix's `owner_bypass` only grants abilities **registered in the
`PermissionRegistry`** (`posts.update`, `reports.access`, …). Policy abilities
(`update`, `delete` with a record) fall through and your policy still decides. If
you roll your own, scope it the same way:

```php
Gate::before(function ($user, string $ability) {
    if (! app(\Happones\Kinetix\Permissions\PermissionRegistry::class)->has($ability)) {
        return null;   // let policies run
    }

    return $user->ownsTeam(currentTeam()) ? true : null;
});
```
:::

---

## 4. Multi-Tenant (Teams) Support

If your application scopes roles and permissions per team, **all four steps are
required** — a common pitfall is enabling only the Kinetix flag:

1. Enable the `teams` setting in `config/kinetix.php`:
   ```php
   'permissions' => [
       'teams' => true,
   ],
   ```
2. Enable spatie's own team scoping in `config/permission.php` — **without this,
   the team id Kinetix sets is silently ignored** and every `hasRole()`/`can()`
   stays global (Kinetix logs a warning at boot when it detects this mismatch):
   ```php
   // config/permission.php
   'teams' => true,
   ```
3. Make the permission tables teams-ready with Kinetix's **hybrid** migration
   (nullable `team_id` outside the primary key, so roles can be global *or*
   team-scoped — spatie's own stub forces every assignment into a team):
   ```bash
   php artisan vendor:publish --tag=kinetix-permission-team-migrations
   php artisan migrate
   ```
   > Already ran spatie's stock `add_teams_fields` migration? Convert manually:
   > make `team_id` nullable on `model_has_roles` / `model_has_permissions`,
   > drop the composite primary key and replace it with a unique index that
   > includes `team_id`. The Kinetix migration skips tables that already have
   > the column.
4. Apply the `kinetix.permissions.team` middleware. Kinetix applies it to its
   **own** routes automatically — for `hasRole()`/`can()` to have team context
   inside *your* routes, append it to your `web` group in `bootstrap/app.php`:
   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->web(append: [
           'kinetix.permissions.team',   // or the FQCN: \Happones\Kinetix\Permissions\Middleware\SetPermissionsTeam::class
       ]);
   })
   ```

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

::: tip URL-driven team context
`SetPermissionsTeam` resolves the active team from the **`{current_team}` route
segment** (translated to a primary key through the user's teams relation — which
doubles as a membership check: a segment the user doesn't belong to 404s),
falling back to the user's `currentTeam` when the route carries no segment. The
permission context always matches the team whose data the request serves.
:::

### Team-scoped vs global roles

Under the hybrid teams schema a role's `team_id` is nullable:

| | Created by | Visible in | Modifiable by |
|---|---|---|---|
| **Team role** (`team_id` set) | The role-management UI inside a team (the current team id is stamped automatically) | Its own team only | That team's `roles.manage` holders |
| **Global role** (`team_id` NULL) | Seeders / console (no team context) — e.g. `KinetixRolesSeeder`'s `admin`/`editor`/`viewer` | Every team (marked with a *Global* badge in the UI) | **Super-admin only** — editing one would change privileges in every team |

The management endpoints are fully tenant-isolated: another team's roles never
appear in the listing, and updating/deleting them by id is a 404 (their
existence is not leaked). Creating — or renaming to — a name that already
exists in scope (same team or global) is a validation error, never a silent
takeover of that role's permissions.

::: tip The classic accident: a seeder that ran without team context
`Role::create(['name' => 'admin'])` from a seeder or `tinker` has **no** team id,
so it lands as a *global* role: visible in every team and editable by
super-admins only — not by the team admins who are supposed to own it. Because
this is invisible in the UI (just a *Global* badge), `kinetix:permissions:sync`
lists them for you whenever team scoping is on:

```
$ php artisan kinetix:permissions:sync

Global (teamless) roles found: admin, editor, viewer
  Team scoping is on, so these are visible in EVERY team and editable by a
  super-admin only. ...
```

Only the protected roles (`permissions.protected_roles`, default: the
super-admin role) are exempt — those *should* be global. To seed a role into a
team, pin the team id first:

```php
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($team->id);
Role::findOrCreate('admin', 'web');
```
:::

---

## 5. Frontend Authorization (Vue / Inertia)

Kinetix **automatically shares** the authenticated user's resolved permissions and
roles via the `kinetix_permissions` Inertia prop — you do **not** need to edit your
`HandleInertiaRequests`. Gate your UI with the shipped helpers, using the same
`{feature}.{ability}` keys the backend enforces. All checks are reactive (they
update when Inertia replaces the page props, e.g. after a role change).

::: danger Never redefine the `kinetix_*` props
Whatever `HandleInertiaRequests::share()` returns is merged **over** what the
package shared, so a `kinetix_permissions` key of your own wins *silently*:
every component keeps reading the prop, it just reads your shape, and `can()`
starts returning `false` everywhere with nothing in the logs.

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),

        // ❌ kills Kinetix's permission gating (and the same applies to every
        //    other kinetix_* prop: kinetix_config, kinetix_teams, …)
        'kinetix_permissions' => [...],

        // ✅ your data goes under your own key
        'acme' => ['flags' => $request->user()?->flags],
    ];
}
```

Kinetix detects this: in **local**, a request whose response no longer carries
the package's own prop logs
`Kinetix: the Inertia prop \`kinetix_permissions\` was replaced by the application …`.
Nothing runs in production.
:::

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

<Screenshot name="role-manager" alt="Role manager" />

### `KinetixRoleMatrix` — the module × ability grid

Prefer a compact, spreadsheet-style editor? `<KinetixRoleMatrix>` shows **role
cards** (with live member counts) and edits each role in a modal whose table has
one row per feature and one column per **canonical** ability (`access`, then
`viewAny → forceDelete`) — the header vocabulary is fixed and never grows. An
em-dash marks abilities a feature doesn't declare; **custom abilities** render
inside their module's expandable row (the `n/m` chip) with their full labels;
`Feature::group()` clusters modules into titled sections. Clicking a module
name toggles its whole row (customs included), and the **header row and module
column stay sticky** while the catalog scrolls. Same endpoints, gating and team
rules as `KinetixRoleManager`:

```vue
<KinetixCan permission="roles.manage">
  <KinetixRoleMatrix />
</KinetixCan>
```

<Screenshot name="role-matrix" alt="Role cards with member counts" />
<Screenshot name="role-matrix-editor" alt="Role editor — module × ability matrix" />

### `KinetixRolesOverview` — audit everything at a glance

Want to see **who can do what without opening each role**?
`<KinetixRolesOverview>` pairs the role cards (member counts + the modules each
role touches) with a **read-only permission matrix**: one row per module, one
column per role. Each cell shows a check (every ability granted), a
`granted/total` badge (partial) or an em-dash (none) — with sticky header row
and module column for large catalogs. The Create button, a card's pencil, or a
role's column header open the same editor modal `KinetixRoleMatrix` uses:

```vue
<KinetixCan permission="roles.manage">
  <KinetixRolesOverview />
</KinetixCan>
```

#### Ready-made page (`kinetix:make-roles-page`)

Scaffold a full page with those features already wired:

```bash
php artisan kinetix:make-roles-page   # --force to overwrite
```

It writes `resources/js/pages/Kinetix/Roles/Index.vue` (the overview behind
`roles.manage`, with a denied fallback) — register just its route; there is no
controller because the component talks to Kinetix's own endpoints:

```php
Route::get('roles', fn () => inertia('Kinetix/Roles/Index'))->name('roles.index');

// Teams on? Nest it under the {current_team} segment like your other routes:
Route::prefix('{current_team}')->group(function () {
    Route::get('roles', fn () => inertia('Kinetix/Roles/Index'))->name('roles.index');
});
```

It talks to the built-in endpoints registered under your Kinetix route prefix,
all gated by `roles.manage` (super-admin bypasses). `{prefix}` below is
`{current_team}/_kinetix` with teams on, `_kinetix` without — never a path of
your own (`php artisan kinetix:routes` prints the resolved URIs):

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/permissions/features` | Permission catalog grouped by feature |
| `GET` | `{prefix}/permissions/roles` | List roles with their permissions + `usersCount` |
| `POST` | `{prefix}/permissions/roles` | Create a role |
| `PUT` | `{prefix}/permissions/roles/{role}` | Rename / sync a role's permissions |
| `DELETE` | `{prefix}/permissions/roles/{role}` | Delete a role |

#### Guardrails against privilege escalation

`roles.manage` lets a user administer roles, but the endpoints stop a manager
from escalating past their own level (all three checks are **bypassed for a
super-admin**):

- **Allowlist** — submitted permission keys are validated against the registry
  (`allPermissions()`), so an unknown or arbitrary key is rejected (`422`) before
  it can reach `syncPermissions()`.
- **Grant only what you hold** — a manager can only assign permissions they
  themselves have; granting one they lack is refused (`403`). The seeded `admin`
  role (all permissions) and any super-admin can therefore grant anything, while
  a limited `roles.manage`-only user cannot escalate.
- **Protected roles & self-lockout** — the roles in `permissions.protected_roles`
  (default: just the super-admin role) can't be created, renamed to, edited or
  deleted here; and any edit/delete that would revoke the actor's **own**
  `roles.manage` is rolled back (`403`).

```php
// config/kinetix.php → permissions
// null protects just the super_admin_role; or list explicit names:
'protected_roles' => ['super-admin', 'owner'],
```

::: warning Behavior change (v0.104.0)
Previously any `roles.manage` holder could grant **any** registered permission.
The "grant only what you hold" guard now restricts that. Give your role
administrators the seeded `admin` role (or super-admin) so they can manage the
full catalog.
:::

Need a custom flow? Compose `KinetixPermissionMatrix` (the feature-grouped grid,
`v-model` of permission keys) with `useKinetixRoles` (the CRUD composable).

<Screenshot name="permission-matrix" alt="Permission matrix" />

### Seeding starter roles

`KinetixRolesSeeder` layers a classic RBAC preset on top of the registry —
`super-admin` (bypasses every gate), `admin` (all permissions), `editor`
(everything except `delete`/`forceDelete`) and `viewer` (read-only):

```bash
php artisan kinetix:permissions:sync   # materialize permissions first
php artisan db:seed --class="Happones\\Kinetix\\Permissions\\KinetixRolesSeeder"
```

---

## 7. Production: caching & deploys

**Edits apply immediately.** spatie caches the permission catalog (permissions +
their role attachments); every mutation the role-management UI performs —
`syncPermissions()`, renames, deletes — flushes that cache automatically, so a
change made in production is enforced on the very next request. No manual
`permission:cache-reset` is needed after using the UI.

What to get right for that to hold at scale:

- **Use a shared cache store on multi-server setups.** spatie's cache lives in
  `config/permission.php → cache.store` (your default store unless overridden).
  With per-server stores (`file`, `array`) a role edited on one node stays stale
  on the others until the TTL (default 24h) expires — point it at Redis or
  Memcached instead.
- **The registry is code, not state.** Feature declarations run at boot from
  your service provider, so `config:cache` / `route:cache` / Octane are all
  safe; nothing about the catalog is read from the database at request time
  except spatie's (cached) permission rows.
- **Deploy checklist**: run `php artisan kinetix:permissions:sync` (idempotent —
  one query to diff, inserts only what's new, flushes spatie's cache once at
  the end) whenever a deploy adds or removes registry declarations; add
  `--prune` if you want obsolete keys removed.
- **Per-request cost.** Kinetix's own layer adds no queries beyond spatie's:
  the `kinetix_permissions` prop is computed once per request from the user's
  (request-cached) relations, skips the dynamic-grant Gate sweep entirely for
  super-admins, and only Gate-checks registered abilities the user doesn't
  already hold as stored rows.
- **Octane / long-running workers.** The super-admin check memoizes per user
  object via a `WeakMap`, so it never leaks across requests; if a worker
  mutates a user's super-admin role mid-process, call `SuperAdmin::flush()`.
