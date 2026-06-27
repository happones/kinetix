---
name: kinetix-connected-accounts
description: "Connected Accounts / social auth: sign in with a provider (guest find-or-create + login), link/unlink OAuth providers, and set a password for social-only users. Requires laravel/socialite. Activates when wiring social login, the connected-accounts manager, or provider linking."
license: MIT
metadata:
  author: happones
---

# Kinetix Connected Accounts Development

## When to Apply

Activate this skill when:
- Adding "sign in with GitHub/Google" (social login/registration).
- Mounting `<KinetixConnectedAccounts>` / using `useKinetixConnectedAccounts`.
- Linking/unlinking OAuth providers or letting social-only users set a password.

The official Laravel Vue starter kit ships **no OAuth** — this is a complete
feature, not a complement. See `docs/starter-kit.md`.

## Documentation

For full details, reference `docs/connected-accounts.md` (published at https://happones.github.io/kinetix/connected-accounts).

## Installation & Configuration

Requires `laravel/socialite` + provider creds in `config/services.php`.

```bash
composer require laravel/socialite
php artisan vendor:publish --tag=kinetix-connected-accounts-migrations
php artisan migrate
```

```php
'connected_accounts' => [
    'enabled'         => env('KINETIX_CONNECTED_ACCOUNTS_ENABLED', false),
    'login_enabled'   => env('KINETIX_CONNECTED_ACCOUNTS_LOGIN', false), // guest social login
    'prevent_lockout' => true,                                            // block unlinking last method w/o password
    'redirect'                => '/settings/security',
    'login_redirect'          => '/dashboard',
    'login_failure_redirect'  => '/login',
    'providers' => [
        'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
        'google' => ['label' => 'Google', 'icon' => 'google', 'color' => '#4285F4'],
    ],
],
```

Migration `kinetix_connected_accounts`: one row per user+provider, unique
`[provider,provider_id]` and `[user_id,provider]`; `token`/`refresh_token` are
**encrypted** casts. The User model needs **no trait** (queried by `user_id`).
The default login creator makes a **passwordless** user → make the users table
`password` column **nullable**.

---

## Backend

```php
use Happones\Kinetix\ConnectedAccounts\KinetixConnectedAccounts;

// Declare providers (provider boot) — or via config above.
KinetixConnectedAccounts::providers([
    'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
]);

// Optional: control login user resolution / creation (e.g. also create a team).
KinetixConnectedAccounts::createUserUsing(fn (object $socialUser, string $provider) => User::create([
    'name' => $socialUser->getName(), 'email' => $socialUser->getEmail(), 'password' => null,
]));
```

- **Self-service** (no admin ability). Authed routes (team-aware):
  `GET {prefix}/connected-accounts` (index: accounts + providers + `hasPassword`),
  `GET redirect/{provider}` + `GET callback/{provider}` (link to current user),
  `POST password` (set/change — `current_password` only required when the user
  already `hasPassword`), `DELETE {account}` (unlink).
- **Guest login** (only when `login_enabled`, `web` middleware, no team prefix):
  `GET login/redirect/{provider}` + `GET login/callback/{provider}` →
  find-or-create + link + `Auth::login(remember: true)`.
- **`ConnectedAccountManager`**: `link()` throws `AccountAlreadyLinkedException`
  when the identity belongs to another user; `unlink()` aborts 422 when removing
  the last sign-in method of a passwordless user (`prevent_lockout`).

---

## Frontend

```vue
<KinetixConnectedAccounts />
```

Lists each provider (built-in `github`/`google` brand glyphs, fallback initial)
with Connect (`<a :href>` full-page OAuth) / Disconnect (inline confirm), plus a
**Set / Change password** form (no current password for social-only users).
`useKinetixConnectedAccounts()` → `{ accounts, providers, hasPassword, loading,
load, connectUrl, disconnect, setPassword }`. DTO never serializes tokens. i18n
`connected_account_*` + `password_*` (en/es/fr/pt).

For login buttons, use `KinetixSocialButton`:

```vue
<KinetixSocialButton provider="github" mode="login" />
<KinetixSocialButton provider="google" mode="link" />  <!-- attach to current user -->
```

Props: `provider`, `mode` (`login` | `link`), `label`, `branded`, `block`,
`variant`, `href`. Brand icons are local SVG components in
`resources/js/icons/brands/` (bundled: github, google, microsoft, gitlab,
bitbucket, facebook, x, apple, discord, twitch) resolved via `@/icons/brands`
(`brandFor(key)`); unknown providers fall back to a generic glyph. Or link
manually to `/{prefix}/connected-accounts/login/redirect/{provider}`.
