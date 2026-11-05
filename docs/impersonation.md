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

    // Optional REPLACEMENT of the escalation guard (see §2): a callable array
    // [Class::class, 'method'] receiving ($impersonator, $target): bool.
    'can_impersonate' => null,
],
```

Each redirect can also be a callable array (not a closure: config must cache)
that receives the user the session belongs to — the target after starting,
the admin after leaving — and returns the URL. In an app with teams, that lands
each one on their own team's pages:

```php
'redirect_to'   => [App\Kinetix\ImpersonationRedirects::class, 'target'],
'redirect_back' => [App\Kinetix\ImpersonationRedirects::class, 'admin'],
```

```php
class ImpersonationRedirects
{
    public static function target(User $user): string
    {
        return route('dashboard', ['current_team' => $user->currentTeam]);
    }

    public static function admin(User $user): string
    {
        return route('configuration.users', ['current_team' => $user->currentTeam]);
    }
}
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
- **It is a platform-level ability.** The target is resolved by global id, so
  holding `users.impersonate` means being able to log in as a user of *any*
  tenant. Kinetix marks it with `Feature::platform()`, which keeps the
  [team-owner bypass](/permissions#team-owners) from ever granting it: with
  `owner_bypass` on, a team owner can **not** impersonate unless the ability is
  granted some other way (a stored permission, a super-admin role, or a
  `Gate::define`). To let owners impersonate the members of their own team,
  follow the recipe in
  [Platform abilities and the impersonation recipe](/permissions#platform-abilities-and-the-impersonation-recipe):
  a `Gate::define('users.impersonate', …)` that checks `TeamOwner::check($user)`,
  plus a `can_impersonate` rule that keeps the built-in guards and keeps every
  impersonator but a super-admin inside the team in the URL (a stored
  permission or role is team-scoped too, so it never means "platform staff").
  Only a super-admin can add `users.impersonate` to a role.
- **Escalation guard**: impersonating someone is inheriting everything they
  hold, in every team they belong to, so the guard judges the target in all of
  them:
  - you cannot impersonate a **super-admin** unless you are one
    (`kinetix.permissions.super_admin_role`, a platform-wide assignment
    included); a super-admin may impersonate anyone else, across teams;
  - with teams on, **every team of the target must be one of yours** (through
    `kinetix.team_switcher.teams_relation`, falling back to `current_team_id`).
    That relation must return every team a user belongs to, owned teams
    included: a Jetstream-style `allTeams()` has to be exposed as a relation
    and named in `teams_relation`, or the teams it misses are never checked.
    A `users.impersonate` grant held in team A never reaches a user who is only
    in team B, even through the bound team in the URL;
  - in **each** of the target's teams, they may not hold a
    `protected_permissions` entry (`roles.manage`) that you don't hold there;
  - a target who **owns** one of their teams needs an impersonator who owns it
    too (ownership follows the `owner_bypass` rule, or `ownsTeam()`);
  - owning one of the target's teams counts as holding a protected permission
    there only when the [owner bypass](/permissions#team-owners) grants it
    (on, inside `only`, outside `except`, not platform-level). With the bypass
    off, or the permission excluded, an owner needs it like anyone else.
- **`can_impersonate` replaces the escalation guard — it does not add to it.**
  A callback (a callable array, never a closure) that only checks "same team"
  lets an owner impersonate a super-admin who belongs to their team. To narrow
  the rule, call the built-in guard first and keep its verdict:

  ```php
  if (! app(\Happones\Kinetix\Impersonation\ImpersonationManager::class)->passesBuiltInGuards($impersonator, $target)) {
      return false;
  }
  // …then your own, narrower rule (same team, lower role rank, …)
  ```
- **Kinetix's own credential routes are blocked while impersonating** (403):
  - API tokens: listing stays readable, creating or revoking a token is
    blocked (`protect:writes`);
  - connected accounts: the link round-trip, setting a password and
    unlinking (`protect`);
  - the password-update endpoints of [Credentials](/credentials)
    (`protect:writes`);
  - GDPR export and deletion (`protect`);
  - browser sessions: listing stays readable, signing sessions out is blocked
    (`protect:writes`);
  - issuing a member's credential (`members/{provision}/credential`,
    `protect`);
  - the billing routes (`protect:writes`).
- **Block your own sensitive actions** while impersonating with the
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

  `kinetix.impersonation.protect:writes` blocks only the requests that change
  something (anything but `GET`, `HEAD` and `OPTIONS`), so the admin still sees
  the page they came to help with — the plan and its invoices — without acting
  on it. It suits a whole group, like the billing routes:

  ```php
  // config/kinetix.php
  'billing' => [
      'middleware' => ['web', 'auth', 'kinetix.impersonation.protect:writes'],
  ],
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
