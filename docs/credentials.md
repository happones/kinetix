# Credentials

How people prove who they are: the **password lifecycle** (expiry, history,
forced change, temporary credentials), and — because the two come up together
— what it takes to let people sign in with a **username or a phone number**
instead of an email.

Everything here is off by default. Enabling the module changes nothing until
you opt into a rule.

---

## Configuration

```php
'credentials' => [
    'enabled' => env('KINETIX_CREDENTIALS_ENABLED', false),

    // What a person may sign in with. ['email'] is exactly today's behavior.
    'identity' => [
        'fields'           => ['email'],
        'phone_country'    => '',   // ISO country assumed for a bare local number
        'username_pattern' => '/^[a-zA-Z0-9._-]{3,32}$/',
    ],

    'passwords' => [
        // Days a password stays valid. null = passwords never expire.
        'expires_after_days' => env('KINETIX_PASSWORD_EXPIRES_DAYS'),

        // How many previous passwords may not be reused (0 = off, max 5).
        'history' => env('KINETIX_PASSWORD_HISTORY', 0),

        // How long an UNUSED temporary credential stays valid.
        'temporary_ttl_hours' => env('KINETIX_PASSWORD_TEMPORARY_TTL', 48),

        // Days before expiry that the UI starts warning (0 = never).
        'warn_before_days' => env('KINETIX_PASSWORD_WARN_DAYS', 7),

        // Extra routes the `kinetix.password` middleware lets through.
        'except' => [],

        'view'           => 'Kinetix/PasswordChange',
        'redirect_after' => '/',
    ],
],
```

## Installation

```bash
php artisan vendor:publish --tag=kinetix-credentials-migrations
php artisan migrate
```

That adds `password_changed_at` and `must_change_password` to your `users`
table, plus the `kinetix_password_history` table (hashes only — see
[Security](#security)).

Then append the middleware to your authenticated group:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', \Happones\Kinetix\Credentials\Middleware\EnsurePasswordIsCurrent::class);
})
```

::: warning A policy without the middleware enforces nothing
Kinetix aliases `kinetix.password` but never applies it — it owns none of your
route groups. Expiry configured without the middleware means passwords go stale
and nobody is ever asked to change one. `php artisan kinetix:doctor` reports
exactly that.
:::

---

## 1. The bookkeeping is automatic

The two things the policy needs to know — *when* the password last changed and
*which* ones have been used — are maintained by a model observer, not by call
sites. A password can be set from Fortify, a reset link, a seeder, an admin
screen or `tinker`, and **a policy that only holds on the path Kinetix owns is
a policy with a hole in it**.

So this is all it takes:

```php
$user->forceFill(['password' => Hash::make($new)])->save();
```

`password_changed_at` is stamped, the history gets an entry, the forced-change
flag clears, and the history is pruned to the configured depth. Nothing to
remember, nothing to wire.

---

## 2. Expiry

```php
'expires_after_days' => 90,
```

A user whose password is older than that is redirected to the change screen by
the middleware, and `KinetixPasswords::isExpired($user)` says so anywhere else.

::: tip Existing accounts are not locked out
Accounts whose password predates the policy have no `password_changed_at`.
Treating that as "expired" would lock out **everyone** the moment an admin
switches the policy on, so a null stamp counts as current until the next
change. If you want the policy to apply retroactively, backfill the column:

```php
User::query()->whereNull('password_changed_at')->update(['password_changed_at' => now()->subDays(90)]);
```
:::

Ask about it directly when you need to:

```php
KinetixPasswords::isExpired($user);          // bool
KinetixPasswords::expiresAt($user);          // ?Carbon
KinetixPasswords::daysUntilExpiry($user);    // ?int — negative once past
```

---

## 3. History

```php
'history' => 3,   // "you can't reuse your last 3 passwords"
```

The **current** password counts as one of them, which is the case a naive
history table gets wrong: with an empty history, a user could "change" their
password to the one they already have.

Add the rule wherever a password is set — including Fortify's own actions,
which is the point of it taking the user explicitly:

```php
// app/Actions/Fortify/UpdateUserPassword.php
use Happones\Kinetix\Credentials\Rules\NotAPreviousPassword;

Validator::make($input, [
    'current_password' => ['required', 'string', 'current_password:web'],
    'password'         => [
        'required', 'string', Password::default(), 'confirmed',
        new NotAPreviousPassword($user),          // ← add this
    ],
])->validateWithBag('updatePassword');
```

Do the same in `ResetUserPassword` and `CreateNewUser` if you want the rule to
apply there too. It is inert while the module is off or `history` is 0, so it
is safe to leave wired.

::: warning The depth is capped at 5, on purpose
Each remembered password costs a `Hash::check`, which is deliberately slow —
that is what a password hash is *for*. At bcrypt's default cost, five
comparisons is already ~½ second on a password change. Kinetix caps the depth
rather than letting a config typo make changing a password take a minute.
:::

---

## 4. Forced change and temporary passwords

```php
// After a breach, or any time you want the next login to change it:
KinetixPasswords::forceChange($user);

// Issue a credential you hand over — returned ONCE, like a Sanctum token:
$plain = KinetixPasswords::issueTemporary($user);
```

`issueTemporary()` hashes on the way in, so the plaintext cannot be read back:
show it now or issue a new one. The user is flagged, so the middleware sends
them to the change screen on their first request — and that screen does **not**
ask for the current password, because an admin chose it and repeating it back
proves nothing.

### Expiring an unused temporary credential

Kinetix does not own your login, so it cannot refuse a stale one for you.

If you use [`KinetixIdentity::attempt()`](#_5-1-fortify) you get it for free —
it already refuses a temporary credential past its TTL. On an email-only app
that keeps Fortify's default login, add the check yourself:

```php
Fortify::authenticateUsing(function (Request $request) {
    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        return null;
    }

    // A temporary credential nobody used within `temporary_ttl_hours` is dead;
    // an admin has to issue a new one.
    if (KinetixPasswords::temporaryHasExpired($user)) {
        return null;
    }

    return $user;
});
```

---

## 5. Signing in with a username or a phone

Many businesses have employees who simply do not have an email address — but
almost all of them have a phone, and a username can always be assigned.

```php
'identity' => [
    'fields'           => ['email', 'username', 'phone'],
    'phone_country'    => 'MX',   // assumed when a number is typed without a country code
    'username_pattern' => '/^[a-zA-Z0-9._-]{3,32}$/',
],
```

```bash
php artisan vendor:publish --tag=kinetix-identity-migrations
php artisan migrate
```

The migration adds only the columns you accept, and relaxes `email` to nullable
**only once something else can identify a person** — changing it while it is the
sole identifier would let an account be created that nobody can log into. The
unique indexes survive the nullability: in MySQL and Postgres NULLs do not
collide, so any number of users may have no email while the ones that do still
cannot share it.

### 5.1 Fortify

```php
// config/fortify.php
'username' => 'login',   // the form field, not the column
```

```php
// app/Providers/FortifyServiceProvider.php
use Happones\Kinetix\Credentials\KinetixIdentity;
use Laravel\Fortify\Fortify;

Fortify::authenticateUsing(fn (Request $request) => KinetixIdentity::attempt(
    $request->input(Fortify::username()),
    $request->input('password'),
));
```

That is the whole change. `attempt()` resolves the login, verifies the password
and refuses a [temporary credential](#4-forced-change-and-temporary-passwords)
past its TTL — then hands the user back for Fortify to log in. Sessions,
throttling and two-factor stay exactly where they were: **Kinetix does not own
your login.**

Your login form posts `login` instead of `email`, and creation forms use the
generated rules:

```php
Validator::make($input, [
    ...KinetixIdentity::rules(),          // or ::rules($user) when updating
    'name' => ['required', 'string', 'max:255'],
])->validate();
```

Each accepted field is nullable and unique, plus a guard that **at least one**
is present — a user nobody can identify is not a user.

### 5.2 The two things that make it safe

**A login is classified before it is queried.** An input that looks like an
email is only ever matched against `email`. Without that, someone could register
another person's email address as their *username* and be found by it — the "I
logged in as the wrong person" bug. The default `username_pattern` excludes `@`
as well, so the collision cannot even be created; `kinetix:doctor` warns if you
widen it.

**Every value is normalized identically going in and coming out.**
`+52 55 1234 5678` and `525512345678` are one phone number, and stored
inconsistently they defeat the unique index that is supposed to stop them being
two accounts. Apply it on writes too:

```php
$user->phone = KinetixIdentity::normalize('phone', $request->input('phone'));
```

::: tip Storage is strict; lookup is forgiving
Without a `+` or `00`, digits are read as a **local** number and get
`phone_country`'s dial code. A bare string that already starts with the country
code is genuinely ambiguous — `52 55 1234 5678` could be a full Mexican number
or a local one that happens to start with 52 — and nothing settles that without
a numbering-plan library. So storage refuses to guess, while **lookup** tries
both readings: they are matched against values that were stored canonically, so
at most one exists. A person can type their number however they know it.
:::

Two people can still match one login when an all-digit string is both a valid
username and a plausible phone. Kinetix resolves that to **nobody** — an
ambiguous identity is worse than a failed login.

Failures are indistinguishable on purpose: `attempt()` returns null for an
unknown identifier, a wrong password and a stale temporary credential alike, and
spends the same time on each, so the form is not a directory anyone can
enumerate. Keep your own error message generic to match.

### 5.3 The consequence: password reset stops working

A user with no email cannot receive a reset link. That is not a detail to
discover in production — decide which of these you want **before** you ship
employee accounts without email:

| Option | What it means |
| --- | --- |
| **Admin-issued temporary password** | The owner issues one and hands it over; the user must replace it on first use. No delivery channel needed at all — this is what §4 is for, and the reason it exists. |
| **Reset by SMS** | A Laravel notification on an SMS channel instead of mail. Needs a provider, and the token is as strong as your phone number's security. |
| **Email required for self-service** | Users without email have no self-service reset, and the help desk issues them a temporary password. |

Also turn off (or make conditional) email verification for these accounts —
`MustVerifyEmail` on a user with a null email will loop them on the "verify your
email" screen forever.

## 6. Frontend

The policy travels on the `kinetix_credentials` Inertia prop, so a banner can
warn before expiry without a round trip:

```ts
const { kinetix_credentials: credentials } = usePage().props;

credentials.expiring;          // inside the warning window
credentials.daysUntilExpiry;   // 3
credentials.requiresChange;    // blocked until they change it
credentials.changeUrl;
```

`<KinetixPasswordChange>` (published) is the screen the middleware redirects
to. Mount it from the page named by `credentials.passwords.view` and pass the
props the controller provides:

```vue
<script setup lang="ts">
import KinetixPasswordChange from '@/components/kinetix/KinetixPasswordChange.vue'

defineProps<{
  action: string
  mustChange: boolean
  expired: boolean
  daysUntilExpiry: number | null
  historyDepth: number
}>()
</script>

<template>
  <KinetixPasswordChange v-bind="$props" />
</template>
```

---

## 7. Security

- **Only hashes are stored.** The history table exists to answer "have you used
  this before?" without anyone — including an operator with database access —
  being able to read what the old passwords were.
- **A temporary password is a weaker credential**, because it travels out of
  band and someone else chose it. Give it a short `temporary_ttl_hours`, enforce
  that TTL at login (§4), rate-limit your login route, and gate *who may issue
  one* behind its own ability rather than lumping it in with "manage members".
- **Issuing one is a privileged act** — log it. If you use the
  [Activity](activity.md) module, record the actor, the target and the time; it
  is the audit trail that makes "someone logged in as an employee" answerable.
- **The middleware answers JSON with 423**, not a redirect, so an XHR doesn't
  silently receive an HTML page it can't interpret.
- **The change screen can never redirect to itself.** It, `login`, `logout`,
  `password.*` and `verification.*` are always exempt — without them a user
  with an expired password could neither fix it nor leave. Add your own with
  `passwords.except`.
- **Deleting a user forgets their history**, which is personal data whose only
  purpose was to answer questions about that account.

---

## Related docs

- [Membership & Provisioning](membership.md) — adding people to a team; the
  provisioning flow that will grow the "no delivery channel" mode this module's
  temporary passwords are for.
- [Activity Log](activity.md) — where to record credential issuance.
- [GDPR](gdpr.md) — `KinetixPasswords::forget($user)` for an erasure request.
