---
name: kinetix-gdpr
description: "GDPR self-service: users export their personal data (queued JSON dump delivered via notification) and delete/anonymize their own account, password-gated. Activates when registering GDPR data sections, mounting the panel, or wiring account deletion."
license: MIT
metadata:
  author: happones
---

# Kinetix GDPR Development

## When to Apply

Activate this skill when:
- Declaring data-export sections (`KinetixGdpr::export`) or a custom delete handler.
- Mounting `<KinetixGdprPanel>` / using `useKinetixGdpr`.
- Configuring account anonymization/deletion.

## Documentation

For full details, reference `docs/gdpr.md` (published at https://happones.github.io/kinetix/gdpr).

## Configuration

```php
'gdpr' => [
    'enabled'          => env('KINETIX_GDPR_ENABLED', false),
    'deletion'         => env('KINETIX_GDPR_DELETION', 'anonymize'), // anonymize | delete
    'require_password' => env('KINETIX_GDPR_REQUIRE_PASSWORD', true),
    'anonymize'        => ['name' => 'Deleted user', 'email' => null], // column => value|closure
    'redirect'         => env('KINETIX_GDPR_REDIRECT', '/'),
],
```

No migration — reuses the exports download route + notifications.

---

## Backend

```php
use Happones\Kinetix\Gdpr\KinetixGdpr;

// Data export sections (provider boot) — resolver gets the user.
KinetixGdpr::export('profile', fn ($user) => $user->only(['name', 'email']));
KinetixGdpr::export('orders', fn ($user) => $user->orders);

// Optional: take over deletion entirely.
KinetixGdpr::deleteUsing(fn ($user) => $user->forceDelete());
```

- **Self-service** (no admin ability): `POST {prefix}/gdpr/export` queues
  `GdprExportJob` (builds a JSON dump of all sections → stores under
  `kinetix-exports/` → notifies with a download link via the exports route);
  `POST {prefix}/gdpr/delete` validates the password (when `require_password`),
  calls `GdprManager::purge`, then logs the user out.
- **`GdprManager::purge`**: custom handler if set; else `deletion='delete'` →
  `$user->delete()`; else **anonymize** (apply the `anonymize` map of
  column→value|closure, save, soft-delete if the model uses SoftDeletes).
- `GdprManager::collect($user)` runs the registered sections (Arrayable →
  `toArray()`).

---

## Frontend

```vue
<KinetixGdprPanel :require-password="true" />
```

"Download your data" (toast: queued) + destructive "Delete account" behind a
confirmation dialog (password field when required); on success logs out and
`router.visit(redirect)`. `useKinetixGdpr()` → `{ exporting, deleting,
exportData, deleteAccount(password?) }`. i18n `gdpr_*` (en/es/fr/pt).
