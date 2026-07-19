---
name: kinetix-locale
description: "A self-service language switcher for Inertia apps, plus how to make Kinetix schemas translatable. List supported locales and mount <KinetixLanguageSwitcher>; the choice persists in session + on the user and applies via App::setLocale(). Activates when adding a language/locale switcher or i18n switching UI, or when localizing labels/headings/placeholders set on Kinetix Tables, Forms, Actions, Infolists or Resources."
license: MIT
metadata:
  author: happones
---

# Kinetix Locale / Language Switcher Development

## When to Apply

Activate this skill when:
- Adding a language / locale switcher to the header (or login screen).
- Persisting a user's preferred language and applying it per request.
- **Localizing the labels/headings you set on Kinetix schemas** (Tables, Forms,
  Actions, Infolists, Resources) — see below.

## Localizing labels declared in PHP (IMPORTANT)

Kinetix ships its own UI strings already localized (7 locales). But **any display
string YOU set on a builder is your app's copy** — wrap it in Laravel's `__()`
helper so it is translatable. Never pass a raw literal.

```php
// ✅ localizable — resolves against your lang files
TextColumn::make('title')->label(__('posts.fields.title'));
TextInput::make('email')->label(__('posts.fields.email'))->placeholder(__('posts.placeholders.email'));
$table->heading(__('posts.table.heading'))->description(__('posts.table.description'));
EditAction::make()->label(__('common.edit'));
SelectFilter::make('status')->options([
    'draft'     => __('posts.status.draft'),
    'published' => __('posts.status.published'),
]);
Section::make(__('posts.sections.meta'));
TextEntry::make('title')->label(__('posts.fields.title'));

// ❌ hardcoded — never translates
TextInput::make('email')->label('Email address');
```

Applies to every display-string setter: `Table::heading()/description()`,
`Column::label()`, form `->label()/->placeholder()/->helperText()`,
`Action::label()/modalHeading()/modalDescription()`, filter/select **option
labels**, `Section`/`Tab` headings, infolist `Entry::label()`, and
`Resource::$navigationLabel`. Put the keys in your own `lang/{locale}/*.php` files
(one per supported locale) — this is standard Laravel i18n; Kinetix just renders
whatever the string resolves to at request time.

Attribute-derived labels (`TextColumn::make('title')` with **no** `->label()`) are
auto-humanized, so only wrap strings you actually type.

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
