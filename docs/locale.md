# Language Switcher

A self-service **language switcher** for your Inertia app: list the locales you
support and drop `<KinetixLanguageSwitcher />` in your header. The choice is
applied instantly in the SPA (vue-i18n), persisted in the session, and — with the
optional migration — saved on the user so it follows them across devices. It
works for guests too, so it can sit on the login screen or a setup wizard.

<Screenshot name="language-switcher" alt="Language switcher dropdown" />

---

## Installation

Enable the feature and list your locales (code → native label, shown in its own
language):

```php
'locale' => [
    'enabled' => env('KINETIX_LOCALE_ENABLED', true),

    'locales' => [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'pt' => 'Português',
    ],

    'store_on_user' => true,        // persist on the user's `locale` column when present
    'session_key'   => 'kinetix.locale',
],
```

### Apply the locale on every request

Add the `kinetix.locale` middleware to your **web** group so each page renders in
the selected language:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Happones\Kinetix\Locale\Middleware\SetKinetixLocale::class,
    ]);
})
```

The middleware reads the persisted locale (user column first, then session) and
calls `App::setLocale()`. It no-ops when the feature is off or nothing is stored.

### Persist across devices (optional)

To remember the choice on the user (not just the session), publish the migration
that adds a nullable `locale` column to `users`:

```bash
php artisan vendor:publish --tag=kinetix-locale-migrations
php artisan migrate
```

Without it, the choice is still remembered for the current session.

---

## The component

```vue
<script setup lang="ts">
import KinetixLanguageSwitcher from '@/components/KinetixLanguageSwitcher.vue';
</script>

<template>
    <!-- icon-only (default) -->
    <KinetixLanguageSwitcher />

    <!-- show the active locale code beside the icon, e.g. "EN" -->
    <KinetixLanguageSwitcher show-label />
</template>
```

It renders a `Languages` icon that opens a dropdown of the supported locales, the
active one marked with a check. Selecting a locale flips the SPA immediately and
persists the choice (rolling back if the request fails). The locales come from
the shared `kinetix_locale` Inertia prop — no extra wiring needed.

`useKinetixLocale()` exposes `{ locales, current, saving, setLocale }` for a
custom UI.

::: tip Compiling the new locale
The switcher only changes the active language — the matching translation messages
must already be loaded by your Vue i18n setup. When you add a locale, re-run your
translation compile step (e.g. `php artisan vue-i18n:generate`) so the strings are
available. See [Installation](/installation).
:::

---

## Endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on). The
switch endpoint is **auth-optional** so it works before login:

| Method | Route             | Name                   |
| ------ | ----------------- | ---------------------- |
| `POST` | `{prefix}/locale` | `kinetix.locale.update` |

Body: `{ "locale": "es" }`. Unsupported codes are rejected with `422`.

---

## Programmatic control

```php
use Happones\Kinetix\Locale\KinetixLocale;

KinetixLocale::set('es');        // persist + apply for the current user
KinetixLocale::current();        // 'es'
KinetixLocale::options();        // [['code' => 'en', 'label' => 'English'], ...]
```
