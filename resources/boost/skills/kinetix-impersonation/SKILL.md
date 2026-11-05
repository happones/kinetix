---
name: kinetix-impersonation
description: "Admin 'log in as user' with an escalation guard, sensitive-route protection and an audit trail. Activates when adding ImpersonateAction, starting/leaving impersonation via KinetixImpersonation, rendering the banner, or protecting routes while impersonating."
license: MIT
metadata:
  author: happones
---

# Kinetix Impersonation Development

## When to Apply

Activate this skill when:
- Adding `ImpersonateAction` to a users table, or starting/leaving via the
  `KinetixImpersonation` facade / `useKinetixImpersonation`.
- Rendering `<KinetixImpersonationBanner>`.
- Guarding the escalation rule (`can_impersonate`) or sealing sensitive routes
  while impersonating (`kinetix.impersonation.protect`).

## Documentation

For full details, reference `docs/impersonation.md` (published at https://happones.github.io/kinetix/impersonation).

## Configuration

```php
'impersonation' => [
    'enabled'       => env('KINETIX_IMPERSONATION_ENABLED', false),
    'redirect_to'   => env('KINETIX_IMPERSONATION_REDIRECT', '/'),
    'redirect_back' => env('KINETIX_IMPERSONATION_REDIRECT_BACK', '/'),
    'can_impersonate' => null, // [Class::class, 'method'] ($impersonator, $target): bool — REPLACES the built-in guards
],
```

No migration — state lives in the session.

---

## Backend Usage

```php
use Happones\Kinetix\Actions\ImpersonateAction;
use Happones\Kinetix\Impersonation\KinetixImpersonation;

// Users table
Table::make(User::query())->recordActions([ImpersonateAction::make()]);

// Programmatic
KinetixImpersonation::start($user);   // gated by users.impersonate + escalation guard
KinetixImpersonation::isImpersonating();
KinetixImpersonation::stop();
```

- **Authorization**: the `users.impersonate` ability (auto-registers with the
  permission matrix). Grant only to admins. It is marked **platform-level**
  (`->platform('impersonate')`), so the team-owner bypass never grants it; for
  owners impersonating their own members, `Gate::define('users.impersonate', [OwnerImpersonation::class, 'allows'])`
  (`TeamOwner::check($user)`) + a `can_impersonate` that keeps the built-in guards and
  keeps EVERY impersonator but a super-admin (`SuperAdmin::check`) inside
  `KinetixTeams::currentTeamKey()` — a stored row/role is team-scoped, never "platform
  staff". Copy the recipe in `docs/permissions.md` verbatim. Only a super-admin can add
  or remove `users.impersonate` on a role (403 otherwise).
- **Escalation guard** (shared with Members credentials, judged in EVERY team of the
  target): no super-admin target unless you are one (teamless assignment included; a
  super-admin crosses teams); with teams on, every team of the target must be yours
  (`team_switcher.teams_relation`, else `current_team_id` — the relation must return
  EVERY team incl. owned ones, e.g. expose Jetstream's `allTeams()` as a relation); in each, no
  `protected_permissions` entry the target holds that you lack; a team owner target
  needs an actor owning that team; an owning actor counts as holding protected P there
  only if `TeamOwner::enabled() && TeamOwner::grants(P)` (bypass on, only/except, not platform).
  `can_impersonate` (a callable array, never a closure) **replaces** it: a callback must
  start with `app(ImpersonationManager::class)->passesBuiltInGuards($impersonator, $target)`
  and only narrow that verdict, or a "same team" rule lets an owner impersonate a super-admin.
- Kinetix's own credential routes are already protected: tokens/sessions/credentials
  password/billing (`protect:writes`), connected-accounts link/callback/password/unlink,
  GDPR and `members/{provision}/credential` (`protect`).
- **Protect your sensitive routes** with the `kinetix.impersonation.protect` middleware
  (password/email/2FA/billing/account-deletion) — it 403s while impersonating.
  `kinetix.impersonation.protect:writes` blocks only changes (non-GET/HEAD/OPTIONS),
  so a whole group like billing stays viewable.
- **Audited**: start/stop log through the Activity event spine
  (`impersonate.start`/`impersonate.stop`, causer = the admin) when Activity is on.
- Endpoints (team-aware): `POST {prefix}/impersonate/{user}` (start),
  `DELETE {prefix}/impersonate` (leave). Target resolved by id via the auth
  provider — no User model reference needed.

---

## Frontend Usage

```vue
<!-- once in the authenticated layout -->
<KinetixImpersonationBanner />
```

Renders nothing unless active; otherwise shows "You are impersonating …" + a
return button. Reads the `kinetix_impersonation` shared prop. Build a custom UI
with `useKinetixImpersonation()` → `active`, `impersonatedName`, `leave()`.
