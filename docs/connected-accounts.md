# Connected Accounts

Kinetix Connected Accounts is a **complete social-auth feature** for the Laravel
Vue starter kit — which ships **no OAuth at all**. It covers the whole story on
top of [Laravel Socialite](https://laravel.com/docs/socialite):

- **Sign in / register with a provider** (guest flow, opt-in): find-or-create the
  user and log them in.
- **Link / unlink providers** (authenticated flow): a drop-in settings manager.
- **Set a password** for social-only users so email + password login also works.

Each authenticated user manages **only their own** linked accounts — there is no
admin ability.

---

## Installation

Connected Accounts requires `laravel/socialite`:

```bash
composer require laravel/socialite
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag=kinetix-connected-accounts-migrations
php artisan migrate
```

Configure your provider credentials in `config/services.php` as usual:

```php
'github' => [
    'client_id'     => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect'      => env('GITHUB_REDIRECT_URI'),
],
```

Then enable the feature and declare the providers you offer:

```php
'connected_accounts' => [
    'enabled' => env('KINETIX_CONNECTED_ACCOUNTS_ENABLED', true),

    // Opt-in guest login/registration via a provider.
    'login_enabled' => env('KINETIX_CONNECTED_ACCOUNTS_LOGIN', false),

    // Block unlinking the last provider when the user has no password set.
    'prevent_lockout' => true,

    'redirect'               => '/settings/security',
    'login_redirect'         => '/dashboard',
    'login_failure_redirect' => '/login',

    'providers' => [
        'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
        'google' => ['label' => 'Google', 'icon' => 'google', 'color' => '#4285F4'],
    ],
],
```

Providers can also be declared from a service provider:

```php
use Happones\Kinetix\ConnectedAccounts\KinetixConnectedAccounts;

KinetixConnectedAccounts::providers([
    'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
]);
```

::: tip Built-in brand icons
The Vue component ships brand glyphs for `github` and `google`. Any other `icon`
falls back to the provider's initial — swap in your own markup if you need more.
:::

::: warning Passwordless users
The default login flow creates social users **without a password** (so
`hasPassword` is false until they set one). Make your users table `password`
column **nullable** for this to work, or supply your own creator (below).
:::

---

## 1. The manager component

Mount the drop-in manager on a security / account settings page:

```vue
<script setup lang="ts">
import KinetixConnectedAccounts from "@/components/kinetix/KinetixConnectedAccounts.vue";
</script>

<template>
  <KinetixConnectedAccounts />
</template>
```

Already titling the section on your page? Pass `hide-title` and the component
skips its own heading (`<KinetixConnectedAccounts hide-title />`).

<Screenshot name="connected-accounts" alt="Connected accounts manager" />

It lists every configured provider with its linked state, a **Connect** link
(starts the OAuth round-trip) or a **Disconnect** control, and — for a user with
no password — a **Set a password** form (no current password required). Users who
already have a password get a **Change password** form instead. All strings are
localized (`connected_account_*` / `password_*` keys, en/es/fr/pt).

---

## 2. Login / registration buttons (opt-in)

Set `login_enabled` to `true` and drop a `KinetixSocialButton` on your login /
register pages. Pass the provider as a prop — it renders the brand icon + label
and links to the right OAuth route:

```vue
<script setup lang="ts">
import KinetixSocialButton from "@/components/kinetix/KinetixSocialButton.vue";
</script>

<template>
  <KinetixSocialButton provider="github" mode="login" />
  <KinetixSocialButton provider="google" mode="login" />
  <KinetixSocialButton provider="microsoft" mode="login" />
</template>
```

<Screenshot name="social-buttons" alt="Social auth login buttons" />

`mode="login"` targets the guest sign-in route; the default `mode="link"`
attaches the provider to the **current** user (used inside the manager).
Props: `provider`, `mode`, `label`, `colorized` (use the provider's **true brand
color** — off by default, so the icon inherits the button text color and
contrasts with the light/dark theme), `block`, `variant`, and `href` (override
the destination). The `KinetixConnectedAccounts` manager takes the same
`colorized` prop.

### Bundled brand icons

Brand glyphs are local SVG components under `resources/js/icons/kinetixBrands/` (no
runtime icon dependency) and resolved through `@/icons/kinetixBrands`. Bundled:
**github, google, microsoft, gitlab, bitbucket, facebook, x (twitter), apple,
discord, twitch**. Unknown providers fall back to a generic link glyph and a
title-cased label, so any Socialite driver still works.

```vue
<!-- The full-page link Kinetix generates, if you prefer raw markup: -->
<a href="/_kinetix/connected-accounts/login/redirect/github">Continue with GitHub</a>
```

On callback Kinetix resolves the user in this order:

1. the user who already linked this provider identity (matched by provider +
   provider id, so a changed email still signs the owner in);
2. your custom resolver (`KinetixConnectedAccounts::resolveUserUsing()`), when
   you registered one;
3. the user who owns the identity's email, **only when** `link_by_email` allows
   it (below) — otherwise the person is sent back to `login_failure_redirect`
   with "An account with this email already exists. Sign in, then connect
   {provider} from your profile.";
4. otherwise a new passwordless user, **only when** the email is verified (or
   `link_by_email` is `always`, or the provider sent no email). Otherwise the
   person is sent back with "This provider didn't confirm your email address.
   Sign up with your email first, then connect {provider} from your profile.":
   an account created on an unverified address would belong to whoever
   controls that provider account, waiting for the address's real owner.
   This check runs before a custom user creator too: if your creator
   verifies emails itself, report that through the `email_verified`
   callback.

It then links the identity and signs the user in, or hands them to the
two-factor challenge first (see [Two-factor](#two-factor-authentication)).
Customize resolution / creation — e.g. to also create a personal team — from a
service provider:

```php
use Happones\Kinetix\ConnectedAccounts\KinetixConnectedAccounts;

KinetixConnectedAccounts::createUserUsing(function (object $socialUser, string $provider) {
    $user = User::create([
        'name'     => $socialUser->getName(),
        'email'    => $socialUser->getEmail(),
        'password' => null,
    ]);

    // e.g. app(CreateTeam::class)->handle($user, "{$user->name}'s Team", personal: true);

    return $user;
});
```

> Already have your own OAuth login controller? Leave `login_enabled` off and use
> only the authenticated link/unlink management — the two coexist cleanly.

### Linking by email

Some providers return an email they never verified (Microsoft multi-tenant
apps, Discord, X, a self-hosted GitLab). Linking on that email alone would let
anyone who registers a provider account with your user's address sign in as
them, so `link_by_email` decides:

| Value                  | A provider login whose email already has an account…                                  |
| ---------------------- | -------------------------------------------------------------------------------------- |
| `verified` (default)   | links and signs in only when the email is verified; otherwise "sign in, then connect"  |
| `never`                | always asks the person to sign in first and connect the provider from their profile    |
| `always`               | links on the email alone — the pre-0.275 behaviour; only safe when every provider verifies emails |

An email counts as verified when:

- the provider is listed in `verified_email_providers` (default `github`,
  `bitbucket`: they only return verified addresses), or
- the provider's own `email_verified` claim is `true` (Google and other OpenID
  Connect providers send it).

Set an `email_verified` callback to decide yourself: when it is set, its answer
is final and the list and the claim are not consulted, so it can also distrust
a provider the defaults trust.

```php
'connected_accounts' => [
    'link_by_email'            => env('KINETIX_CONNECTED_ACCOUNTS_LINK_BY_EMAIL', 'verified'),
    'verified_email_providers' => ['github', 'bitbucket'],

    // A callable array receiving ($socialiteUser, string $provider): bool.
    'email_verified' => [\App\Kinetix\SocialEmails::class, 'verified'],
],
```

`kinetix:doctor` warns when provider login is on with `link_by_email = always`.

### Two-factor authentication

A provider login never skips two-factor. When the resolved user has it turned on
(a `two_factor_secret`, plus `two_factor_confirmed_at` when Fortify requires
confirmation), Kinetix does what Fortify's password login does: it puts
`login.id` and `login.remember` in the session, sets the intended URL to
`login_redirect`, fires Fortify's `TwoFactorAuthenticationChallenged` event when
Fortify is installed, and redirects to the `two-factor.login` route. The user
stays a guest until they pass the challenge. When the app has no
`two-factor.login` route, the login is refused with "This account uses
two-factor authentication. Sign in with your password instead."

Using another two-factor system? Point `two_factor_challenge` at a callable
array receiving `($user, Request $request, bool $remember)`. Return a response
(your challenge) or `null` to sign the user in straight away; it replaces the
built-in check.

```php
'two_factor_challenge' => [\App\Kinetix\SocialTwoFactor::class, 'challenge'],
```

---

## 3. Account lockout protection

A user who only ever signed in with a provider has no password. Unlinking their
last account would lock them out, so by default Kinetix blocks it (HTTP 422) and
the UI nudges them to set a password first. Disable with
`connected_accounts.prevent_lockout = false`.

---

## 4. The endpoints

Registered under your Kinetix prefix. Link/unlink/index/password are
authenticated (team-aware when `kinetix.teams` is on); the login routes are
guest-accessible and only registered when `login_enabled` is true.

| Method   | Route                                              | Name                                       |
| -------- | -------------------------------------------------- | ------------------------------------------ |
| `GET`    | `{prefix}/connected-accounts`                      | `kinetix.connected-accounts.index`         |
| `GET`    | `{prefix}/connected-accounts/redirect/{provider}`  | `kinetix.connected-accounts.redirect`      |
| `GET`    | `{prefix}/connected-accounts/callback/{provider}`  | `kinetix.connected-accounts.callback`      |
| `POST`   | `{prefix}/connected-accounts/password`             | `kinetix.connected-accounts.password`      |
| `DELETE` | `{prefix}/connected-accounts/{account}`            | `kinetix.connected-accounts.destroy`       |
| `GET`    | `{prefix}/connected-accounts/login/redirect/{provider}` | `kinetix.connected-accounts.login.redirect` |
| `GET`    | `{prefix}/connected-accounts/login/callback/{provider}` | `kinetix.connected-accounts.login.callback` |

While an admin is impersonating someone, the link round-trip (`redirect`,
`callback`), `password` and `destroy` answer 403: an impersonator can't attach
their own provider to the account or change how it signs in.

Access / refresh tokens are stored **encrypted** and never serialized to the
client. `index` returns the caller's accounts, the provider catalog (with a
`linked` flag) and `hasPassword`.

## Rate limit on the password check

Setting or changing the password here shares ONE per-user budget with every other Kinetix
endpoint that confirms the current password (the confidential unlock,
deleting the account, logging out other devices, the connected-accounts
password and the password change): 5 attempts a minute by default, so a
hijacked session can't guess the password online, not even by rotating
endpoints. Every attempt counts, the successful ones too.

```php
// config/kinetix.php
'password_confirmation' => [
    'throttle' => '5,1', // "attempts,minutes"; false turns it off
],
```

Over the limit the endpoint answers `429` with a translated message:
`{"message": "Too many attempts. Try again in 42 seconds.", "code": "throttled", "retry_after": 42, "errors": {"password": ["…"]}}`
plus the `Retry-After` header. An Inertia request (the password change
screen) is sent back with the message as the `password` field error.
