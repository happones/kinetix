---
name: kinetix-credentials
description: "Login identity (sign in with username or phone, not just email) and the password lifecycle — expiry, history (no reuse), forced change and admin-issued temporary passwords — plus what it takes to sign in with a username or phone instead of email. Activates when configuring password policy, adding NotAPreviousPassword, wiring the kinetix.password middleware, issuing temporary credentials, or changing Fortify to accept a non-email identifier."
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
    'identity' => [
        'fields'           => ['email'],   // + 'username' / 'phone'
        'phone_country'    => '',
        'username_pattern' => '/^[a-zA-Z0-9._-]{3,32}$/',
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

## Username / phone login (`credentials.identity`)

```php
'identity' => [
    'fields'           => ['email', 'username', 'phone'],  // ['email'] = today
    'phone_country'    => 'MX',   // assumed for a bare local number
    'username_pattern' => '/^[a-zA-Z0-9._-]{3,32}$/',
],
```

Publish `--tag=kinetix-identity-migrations` (adds only the accepted columns, and
relaxes `email` to nullable ONLY once something else can identify a person).

Fortify is one line — Kinetix does NOT own the login, `attempt()` just resolves
and verifies, then hands the user back:

```php
// config/fortify.php → 'username' => 'login'
Fortify::authenticateUsing(fn (Request $request) => KinetixIdentity::attempt(
    $request->input(Fortify::username()),
    $request->input('password'),
));
```

```php
KinetixIdentity::fields();                    // accepted columns
KinetixIdentity::classify($login);            // which field(s) it could be
KinetixIdentity::resolve($login);             // ?Model
KinetixIdentity::attempt($login, $password);  // ?Model — + temp-TTL refusal
KinetixIdentity::normalize('phone', $input);  // APPLY THIS ON WRITES TOO
KinetixIdentity::rules($ignore);              // validation for create/update
```

### REQUIRED rules

- **Normalize on writes.** `$user->phone = KinetixIdentity::normalize('phone', $input)`.
  A unique index cannot see duplicates it isn't shown — `+52 55 1234 5678` and
  `525512345678` would otherwise become two accounts.
- **Never widen `username_pattern` to allow `@`.** Classification stops an email
  being MATCHED against a username, but a pattern with `@` lets the collision be
  CREATED (registering someone else's email as your username). `kinetix:doctor`
  warns.
- **Storage is strict, lookup is forgiving.** Without `+`/`00`, digits are a
  LOCAL number. A bare string starting with the country code is ambiguous, so
  only `resolve()` tries both readings. Do NOT add a heuristic to `normalize()`.
- **Keep login errors generic.** `attempt()` returns null for unknown
  identifier, wrong password and stale temporary credential alike, and spends
  the same time on each. A message that distinguishes them is an enumerable
  directory.
- **Two users matching one login resolves to NOBODY** (an all-digit string is
  both a valid username and a plausible phone). Ambiguous identity is worse than
  a failed login.

**Plan for the consequence:** a user with no email cannot receive a password
reset. Pick one before shipping — admin-issued temporary password (what this
module is for), reset by SMS, or no self-service reset for those accounts. Also
disable/condition `MustVerifyEmail`, or a null-email user loops on the verify
screen forever.

## Files

- `src/Credentials/{PasswordPolicy,PasswordObserver,PasswordHistory,KinetixPasswords,PasswordController}.php`
- `src/Credentials/{IdentityResolver,KinetixIdentity}.php`
- `src/Credentials/Rules/NotAPreviousPassword.php`
- `src/Credentials/Middleware/EnsurePasswordIsCurrent.php` · alias `kinetix.password`
- `resources/js/components/KinetixPasswordChange.vue` · prop `kinetix_credentials`
- `database/migrations/*_create_kinetix_password_history_table.php` · `*_add_kinetix_password_fields_to_users_table.php` · `*_add_kinetix_identity_fields_to_users_table.php`
