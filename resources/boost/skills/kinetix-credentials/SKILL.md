---
name: kinetix-credentials
description: "Password lifecycle — expiry, history (no reuse), forced change and admin-issued temporary passwords — plus what it takes to sign in with a username or phone instead of email. Activates when configuring password policy, adding NotAPreviousPassword, wiring the kinetix.password middleware, issuing temporary credentials, or changing Fortify to accept a non-email identifier."
license: MIT
metadata:
  author: happones
---

# Kinetix Credentials Development

## When to Apply

Activate this skill when:
- Configuring password expiry, history or forced change.
- Issuing a temporary password an admin hands over (employees with no email).
- Wiring the `kinetix.password` middleware or the change-password screen.
- Adding `NotAPreviousPassword` to a Fortify action.
- Changing an app to sign in with `username` / `phone` instead of `email`.

## Documentation

For full details, reference `docs/credentials.md` (published at https://happones.github.io/kinetix/credentials).

## Configuration

```php
'credentials' => [
    'enabled' => env('KINETIX_CREDENTIALS_ENABLED', false),
    'passwords' => [
        'expires_after_days'  => null,   // null = never expire
        'history'             => 0,      // 0 = off, capped at 5
        'temporary_ttl_hours' => 48,
        'warn_before_days'    => 7,
        'except'              => [],     // extra routes the middleware lets through
        'view'                => 'Kinetix/PasswordChange',
        'redirect_after'      => '/',
    ],
],
```

Publish with `--tag=kinetix-credentials-migrations` (adds `password_changed_at`
and `must_change_password` to `users`, plus `kinetix_password_history`).

---

## Key Rules

- **Never call the bookkeeping by hand.** `PasswordObserver` stamps the change,
  appends to the history, clears the forced-change flag and prunes — on the
  user model's own events. `$user->forceFill(['password' => Hash::make($x)])->save()`
  is the whole API. A policy that only holds on the path Kinetix owns has a hole
  in it (Fortify, resets, seeders all change passwords).
- **Kinetix does NOT apply the middleware.** It aliases `kinetix.password`;
  the host appends it to their group. Expiry configured without it enforces
  NOTHING — `kinetix:doctor` errors on that.
- **A null `password_changed_at` means "predates the policy" and is treated as
  CURRENT**, never expired. Otherwise enabling expiry locks out every existing
  account at once. Backfill the column if you want it retroactive.
- **The current password counts as "used before".** With an empty history a
  user could otherwise "change" to the password they already have.
- **History is capped at 5** — each remembered password is a deliberately slow
  `Hash::check`. Never raise it; five is already ~½s per change.
- **A temporary password does NOT ask for the current one** on the change
  screen: an admin chose it, so repeating it back proves nothing. An EXPIRED
  password is still the user's own, so it IS required.
- **`issueTemporary()` returns the plaintext ONCE** (hashed on the way in, like
  a Sanctum token). Show it or reissue.
- **Only hashes are stored**, ever. Never log or persist a plaintext password.

## Usage

```php
use Happones\Kinetix\Credentials\KinetixPasswords;

KinetixPasswords::isExpired($user);              // bool
KinetixPasswords::daysUntilExpiry($user);        // ?int (negative once past)
KinetixPasswords::requiresChange($user);         // expired OR flagged
KinetixPasswords::forceChange($user);            // after a breach
$plain = KinetixPasswords::issueTemporary($user); // shown ONCE
KinetixPasswords::temporaryHasExpired($user);    // enforce at YOUR login
KinetixPasswords::forget($user);                 // GDPR erasure
```

Add the rule to Fortify's actions (inert while the module/history is off):

```php
use Happones\Kinetix\Credentials\Rules\NotAPreviousPassword;

'password' => ['required', 'confirmed', Password::default(), new NotAPreviousPassword($user)],
```

## Middleware exemptions (the classic bug)

`login`, `logout`, `password.*`, `verification.*` and the change screen itself
are ALWAYS exempt — without them a user with an expired password can neither
fix it nor leave, and the middleware redirects the change screen to itself. Add
your own with `passwords.except` (route names, fnmatch patterns, or paths).
JSON requests get a **423**, not a redirect.

## Username / phone login (host-side, NOT owned by Kinetix)

Kinetix supplies the password half; the identity half is the host's:

1. **Migration** — add `username` / `phone` (nullable + unique), make `email`
   nullable. NULLs don't collide in MySQL/Postgres so the unique index still
   works. **Normalize phones to E.164** or the index is decoration
   (`Support\DialCodes` + `<KinetixPhoneInput>` already emit that shape).
2. **Fortify** — `config('fortify.username') = 'login'` plus
   `Fortify::authenticateUsing()` resolving against the accepted columns.
   Branch on `FILTER_VALIDATE_EMAIL` so an email can't also match a username,
   and return ONE generic failure message (a "no user with that phone" message
   is an enumerable directory).
3. **Validation** — stop requiring `email`; require one of the identifiers.

**Plan for the consequence:** a user with no email cannot receive a password
reset. Pick one before shipping — admin-issued temporary password (what this
module is for), reset by SMS, or no self-service reset for those accounts. Also
disable/condition `MustVerifyEmail`, or a null-email user loops on the verify
screen forever.

## Files

- `src/Credentials/{PasswordPolicy,PasswordObserver,PasswordHistory,KinetixPasswords,PasswordController}.php`
- `src/Credentials/Rules/NotAPreviousPassword.php`
- `src/Credentials/Middleware/EnsurePasswordIsCurrent.php` · alias `kinetix.password`
- `resources/js/components/KinetixPasswordChange.vue` · prop `kinetix_credentials`
- `database/migrations/*_create_kinetix_password_history_table.php` · `*_add_kinetix_password_fields_to_users_table.php`
