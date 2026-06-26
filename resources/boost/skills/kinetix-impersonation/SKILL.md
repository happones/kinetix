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
    'can_impersonate' => null, // fn (Authenticatable $impersonator, Authenticatable $target): bool
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
  permission matrix). Grant only to admins.
- **Escalation guard**: cannot impersonate a super-admin unless you are one;
  override with the `can_impersonate` closure for finer rules.
- **Protect sensitive routes** with the `kinetix.impersonation.protect` middleware
  (password/email/2FA/billing/account-deletion) — it 403s while impersonating.
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
