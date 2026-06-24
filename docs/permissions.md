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
    // Enable the permissions registry & super-admin gate checks
    'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', true),

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

This middleware automatically reads the current active team from the authenticated user (`$user->currentTeam`) and sets it on Spatie's permission registrar using:

```php
app(PermissionRegistrar::class)->setPermissionsTeamId($teamId);
```

This ensures roles and permissions are resolved only within the context of the active team.
