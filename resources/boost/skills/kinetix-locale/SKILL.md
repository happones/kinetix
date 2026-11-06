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

// Permission features are declared at BOOT, before the request locale is set:
// pass the KEY (or a JSON-catalog string) — Kinetix translates it when shown.
KinetixPermissions::feature('employees')->label('perms.employees')->group('perms.hr')
    ->ability('viewSalary', 'perms.view_salary');   // ✅ not __('perms.view_salary')
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
`{ locales, current, saving, setLocale }`. `setLocale` flips vue-i18n instantly
(after loading the messages, with lazy locales), persists, then
`router.reload()` (rolls back on failure). i18n `language`.
`variant="select"` renders a labelled Select field instead; that variant's
field is loaded on demand where it's used, the default dropdown stays static.

> The host app owns the vue-i18n instance (`locale: page.props.locale`). When you
> add a locale, re-run your translation compile step so its messages are loaded.

## Lazy locales (opt-in)

Eager wiring imports every locale into the entry bundle. To load only the
active locale + fallback: `vue-i18n:generate --multi-locales`,
`'translations' => ['vue_i18n_options' => ['--multi-locales' => true]]`, then
`php artisan kinetix:install --lazy-locales` (fresh entry) or the snippet by hand.

```ts
import { attachKinetixI18n, kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';

registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(import.meta.glob(['./locales/*.ts', '!./locales/index.ts'])),
    fallbackLocale: 'en',
});
// createInertiaApp({ resolve: withKinetixLocales(<existing resolve>), withApp(app, { page }) {
//     const i18n = createI18n({ legacy: false, locale: kinetixPageLocale(page), fallbackLocale: 'en', messages: kinetixLoadedMessages() });
//     attachKinetixI18n(i18n); app.use(i18n); … } })
```

Rules:
- REQUIRED: wrap `resolve` with `withKinetixLocales()`; never load messages
  inside `withApp` (it is synchronous, so the first paint would show raw keys).
- REQUIRED: create the i18n instance inside `withApp` (per request on SSR),
  never at module scope; the SSR entry needs the same register + wrapper.
- REQUIRED: wire EVERY entry (`ssr.ts`, `public.ts`…). An eager entry keeps all
  locales and its static import of the per-locale files breaks their chunking.
- Root `lang/*.json` catalogs: `--multi-locales` doesn't emit them. Adding
  `import.meta.glob('../../lang/*.json')` as a FIRST map loads them RAW (no
  `:name` → `{name}`, plural or `@` conversion; `@`/`{}` can break vue-i18n's
  compiler). Opt-in only, for catalogs already in vue-i18n syntax; never in
  the default wiring.
- Pays off from ~3 locales or when most visitors use the fallback: lazy mode
  always blocks on active + fallback. With 2 locales and app locale ≠ fallback
  it is neutral to slightly worse; `withKinetixLocales(resolve, {
  preloadFallback: 'idle' })` defers the fallback when the active locale is
  complete (with SSR, a missing key then causes a hydration mismatch). The
  vue-i18n runtime stays in the entry.
- `setLocale()` awaits the messages before flipping; on a failed load it throws
  and leaves the locale unchanged. `es-MX` falls back to `es.ts`.
- Don't `mergeLocaleMessage()` into loaded trees on the server (they're shared
  across requests).
