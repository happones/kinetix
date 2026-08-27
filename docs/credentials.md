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

Kinetix does not own your login, so it cannot refuse a stale one for you. Add
one line where you authenticate:

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
almost all of them have a phone, and a username can always be assigned. The
starter kit assumes email everywhere, so this takes changes in **your** app:
Kinetix supplies the password half, you own the identity half.

There are three places to touch, and one consequence to plan for.

### 5.1 The migration

Make `email` optional and add the fields you want to accept:

```php
// database/migrations/xxxx_xx_xx_add_login_identifiers_to_users_table.php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('username')->nullable()->unique()->after('name');
        $table->string('phone')->nullable()->unique()->after('username');
    });

    // Email stops being mandatory. Keep the unique index: NULLs do not collide
    // in MySQL or Postgres, so any number of users can have no email while
    // those that do still can't share one.
    Schema::table('users', function (Blueprint $table) {
        $table->string('email')->nullable()->change();
    });
}
```

::: warning `->change()` needs `doctrine/dbal` on older setups
Laravel 11+ changes columns natively. On an older app, `composer require
doctrine/dbal` first — and check the column definition it generates before
running it in production.
:::

Store phones in **one canonical format** or the unique index is decoration:
`+52 55 1234 5678` and `525512345678` are the same number and would both
insert. Normalize to E.164 on the way in — Kinetix ships `Support\DialCodes`
and `<KinetixPhoneInput>`, which already emit a country code plus digits.

### 5.2 Fortify

Tell Fortify which request field carries the identifier, then resolve it
against whichever columns you accept:

```php
// config/fortify.php
'username' => 'login',   // the form field, not the column
```

```php
// app/Providers/FortifyServiceProvider.php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Happones\Kinetix\Credentials\KinetixPasswords;

public function boot(): void
{
    Fortify::authenticateUsing(function (Request $request) {
        $login = (string) $request->input('login');

        $user = User::query()
            ->when(filter_var($login, FILTER_VALIDATE_EMAIL), fn ($q) => $q->orWhere('email', $login))
            ->when(! filter_var($login, FILTER_VALIDATE_EMAIL), function ($q) use ($login) {
                $q->where('username', $login)
                  ->orWhere('phone', $this->normalizePhone($login));
            })
            ->first();

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            return null;
        }

        if (KinetixPasswords::temporaryHasExpired($user)) {
            return null;
        }

        return $user;
    });
}
```

Two details worth getting right:

- **Look up by exactly one shape at a time.** Branching on
  `FILTER_VALIDATE_EMAIL` keeps an input that *is* an email from also matching
  someone's username, which is how "I logged in as the wrong person" bugs
  happen.
- **Do not leak which field matched.** One generic "these credentials do not
  match our records" for every failure; a message that says "no user with that
  phone" is a directory anyone can enumerate.

Your login form then posts `login` instead of `email`, and the validation in
`CreateNewUser` / your provisioning screen has to stop requiring `email` and
require *one of* the accepted identifiers instead:

```php
Validator::make($input, [
    'email'    => ['nullable', 'email', 'max:255', Rule::unique(User::class)],
    'username' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9._-]+$/i', Rule::unique(User::class)],
    'phone'    => ['nullable', 'string', 'max:20', Rule::unique(User::class)],
    // At least one way to identify this person.
    'identifier' => ['required_without_all:email,username,phone'],
])->validate();
```

### 5.3 The consequence: password reset stops working

A user with no email cannot receive a reset link. That is not a detail to
discover in production — decide which of these you want **before** you ship
employee accounts without email:

| Option | What it means |
| --- | --- |
| **Admin-issued temporary password** | The owner issues one and hands it over; the user must replace it on first use. No delivery channel needed at all — this is what §4 is for, and the reason it exists. |
| **Reset by SMS** | A Laravel notification on an SMS channel instead of mail. Needs a provider, and the token is as strong as your phone number's security. |
| **Email required for self-service** | Users without email simply have no self-service reset, and the help desk issues them a temporary password. |

Also turn off (or make conditional) email verification for these accounts —
`MustVerifyEmail` on a user with a null email will loop them on the "verify
your email" screen forever.

::: tip A resolver is coming
The lookup above is deliberately plain Laravel so it works today. A configurable
`credentials.identity` block — accepted fields, phone normalization and a
one-line resolver — is the next piece of this module; this section will shrink
when it lands.
:::

---

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
