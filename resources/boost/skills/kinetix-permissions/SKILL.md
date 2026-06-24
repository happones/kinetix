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
- Sharing user permissions with Inertia page props inside `HandleInertiaRequests.php`.
- Implementing frontend role or permission authorization checks in Vue templates using custom wrappers or global `$can` / local `can()` helper.
- Implementing multi-tenant/team scoped permissions with the `kinetix.permissions.team` middleware.

## Documentation

For full details, reference the [Kinetix Permissions Documentation](file:///home/happones/Plugins/Php/kinetix/docs/permissions.md).

## Configuration

Ensure the permissions module is enabled in `config/kinetix.php`:

```php
'permissions' => [
    'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', true),
    'teams'            => env('KINETIX_PERMISSIONS_TEAMS', false),
    'super_admin_role' => env('KINETIX_SUPER_ADMIN_ROLE', 'super-admin'),
    'guard'            => env('KINETIX_PERMISSIONS_GUARD', 'web'),
],
```

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

### 1. Exposing Permissions in Inertia
Share the user permissions in `app/Http/Middleware/HandleInertiaRequests.php`:

```php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        'auth' => [
            'user' => $request->user() ? [
                'id'          => $request->user()->id,
                'name'        => $request->user()->name,
                'permissions' => $request->user()->getAllPermissions()->pluck('name')->toArray(),
            ] : null,
        ],
    ]);
}
```

### 2. Checking Permissions in Vue Components
Define a local helper using `usePage()`:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const can = (permission: string): boolean => {
  const permissions = (page.props.auth as any)?.user?.permissions ?? []
  return permissions.includes(permission)
}
</script>

<template>
  <button v-if="can('posts.create')">Create Post</button>
</template>
```

### 3. Registering Global `$can` Helper
In your `app.ts` / `app.js` entry file:

```typescript
app.config.globalProperties.$can = (permission: string): boolean => {
  const permissions = (props.initialPage.props.auth as any)?.user?.permissions ?? []
  return permissions.includes(permission)
}
```
And check in template directly:
```html
<button v-if="$can('posts.create')">Create Post</button>
```
