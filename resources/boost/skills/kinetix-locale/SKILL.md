---
name: kinetix-locale
description: "A self-service language switcher for Inertia apps. List supported locales and mount <KinetixLanguageSwitcher>; the choice persists in session + on the user and applies via App::setLocale(). Activates when adding a language/locale switcher or i18n switching UI."
license: MIT
metadata:
  author: happones
---

# Kinetix Locale / Language Switcher Development

## When to Apply

Activate this skill when:
- Adding a language / locale switcher to the header (or login screen).
- Persisting a user's preferred language and applying it per request.

## Documentation

For full details, reference `docs/locale.md` (published at https://happones.github.io/kinetix/locale).

## Configuration

```php
'locale' => [
    'enabled' => env('KINETIX_LOCALE_ENABLED', false),
    'locales' => [ // code => native label (shown in its own language)
        'en' => 'English', 'es' => 'Español', 'fr' => 'Français', 'pt' => 'Português',
    ],
    'store_on_user' => true,         // persist on the user's `locale` column when present
    'session_key'   => 'kinetix.locale',
],
```

## Apply the locale per request

Add the always-aliased middleware to the **web** group:

```php
// bootstrap/app.php
$middleware->web(append: [\Happones\Kinetix\Locale\Middleware\SetKinetixLocale::class]);
```

## Persist across devices (optional)

```bash
php artisan vendor:publish --tag=kinetix-locale-migrations
php artisan migrate   # adds nullable `locale` to users
```

Without it the choice is still remembered in the session.

## Backend

- `LocaleManager` (singleton): `resolve($user?)` (user column → session → null),
  `apply()` (`App::setLocale`), `set($code, $user?)`, `options()`, `current()`.
  `KinetixLocale::set()/current()/options()` static facade.
- `LocaleController` `POST {prefix}/locale` — **auth-optional**, team-aware,
  validates the code (422 if unsupported).
- Inertia share `kinetix_locale` = `{enabled, current, locales:[{code,label}]}`.

## Frontend

```vue
<KinetixLanguageSwitcher />            <!-- icon only -->
<KinetixLanguageSwitcher show-label /> <!-- show the active code, e.g. "EN" -->
```

`Languages` trigger → dropdown of locales (active marked). `useKinetixLocale()` →
`{ locales, current, saving, setLocale }`. `setLocale` flips vue-i18n instantly,
persists, then `router.reload()` (rolls back on failure). i18n `language`.

> The host app owns the vue-i18n instance (`locale: page.props.locale`). When you
> add a locale, re-run your translation compile step so its messages are loaded.
