# Impersonation

Kinetix Impersonation lets an admin **"log in as" another user** to reproduce a
problem or support them — safely. The `users.impersonate` ability controls who
may impersonate, a built-in escalation guard blocks impersonating a super-admin,
sensitive routes can be sealed off while impersonating, and every session is
recorded through the [Activity](/activity) event spine.

---

## Configuration

Enable it in `config/kinetix.php` (opt-in, default off):

```php
'impersonation' => [
    'enabled'       => env('KINETIX_IMPERSONATION_ENABLED', false),
    'redirect_to'   => env('KINETIX_IMPERSONATION_REDIRECT', '/'),       // after start
    'redirect_back' => env('KINETIX_IMPERSONATION_REDIRECT_BACK', '/'),  // after leaving

    // Optional override of the escalation guard.
    // fn (Authenticatable $impersonator, Authenticatable $target): bool
    'can_impersonate' => null,
],
```

No migration — impersonation lives entirely in the session.

---

## 1. Starting & leaving

Add the `ImpersonateAction` to your users table; it posts to the Kinetix endpoint
and reloads the page as the target user:

```php
use Happones\Kinetix\Actions\ImpersonateAction;

Table::make(User::query())->recordActions([
    ImpersonateAction::make(), // authorized by `users.impersonate`
]);
```

Mount the banner once in your authenticated layout so the admin always has a way
back:

```vue
<script setup lang="ts">
import KinetixImpersonationBanner from '@/components/kinetix/KinetixImpersonationBanner.vue'
</script>

<template>
  <KinetixImpersonationBanner />
  <slot />
</template>
```

It renders nothing unless a session is active; otherwise it shows
*"You are impersonating …"* with a **Return to your account** button.

<Screenshot name="impersonation-banner" alt="Impersonation banner" />

Programmatically:

```php
use Happones\Kinetix\Impersonation\KinetixImpersonation;

KinetixImpersonation::start($user);
KinetixImpersonation::isImpersonating();   // true
KinetixImpersonation::stop();
```

---

## 2. Safety

This is the part that matters — impersonation done naively is a privilege-escalation hole.

- **Who can impersonate** is the `users.impersonate` ability (registers with the
  permission matrix when [Permissions](/permissions) is on). Grant it only to
  admins.
- **Escalation guard**: you cannot impersonate a **super-admin** unless you are
  one (`kinetix.permissions.super_admin_role`). Tighten it further with the
  `can_impersonate` closure — e.g. restrict to users in the same team, or with a
  lower role rank.
- **Block sensitive actions** while impersonating with the
  `kinetix.impersonation.protect` middleware. Apply it to anything that changes
  credentials or spends money so an admin acting as a user can't, say, change
  their password or buy a plan:

  ```php
  Route::middleware('kinetix.impersonation.protect')->group(function () {
      Route::put('/user/password', ...);
      Route::put('/user/email', ...);
      Route::post('/billing/subscribe', ...);
      Route::delete('/user', ...);  // account deletion
  });
  ```

- **Audited**: start and stop are logged through the Activity event spine
  (`impersonate.start` / `impersonate.stop`, causer = the admin) when the Activity
  module is enabled — so impersonation is never invisible.

---

## 3. Endpoints

Team-aware, under the Kinetix route prefix:

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `{prefix}/impersonate/{user}` | Start (gated by `users.impersonate` + the escalation guard) |
| `DELETE` | `{prefix}/impersonate` | Leave (open — the impersonated user must be able to exit) |

The target is resolved by id through the auth provider, so the package needs no
reference to your `User` model. `useKinetixImpersonation()` exposes `active`,
`impersonatedName` and `leave()` if you want to build your own banner.
