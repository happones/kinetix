# Language Switcher

A self-service **language switcher** for your Inertia app: list the locales you
support and drop `<KinetixLanguageSwitcher />` wherever it belongs. The choice is
applied instantly in the SPA (vue-i18n), persisted in the session, and — with the
optional migration — saved on the user so it follows them across devices. It
works for guests too, so it can sit on the login screen or a setup wizard.

It renders in **two shapes**, so the same state can be exposed in two very
different places:

| Variant | Shape | Use it in |
| --- | --- | --- |
| `dropdown` (default) | A `Languages` icon that opens a menu of locales | A header, navbar or toolbar — anywhere a labelled field would be out of place |
| `select` | A labelled Select field | A settings page, profile form or onboarding step, among other fields |

```vue
<!-- header -->
<KinetixLanguageSwitcher />

<!-- settings page -->
<KinetixLanguageSwitcher variant="select" />
```

Both drive the same state, so you can use **both at once** — switching in one
updates the other immediately, with no page reload.

<Screenshot name="language-switcher" alt="Language switcher dropdown" />

---

## Installation

Enable the feature and list your locales (code → native label, shown in its own
language):

> Kinetix ships its own UI strings in **en, es, fr, pt, zh, ja, ru** — list
> only the locales your app supports (and match
> `kinetix.translations.locales` so only those catalogs get published).

```php
'locale' => [
    'enabled' => env('KINETIX_LOCALE_ENABLED', false),

    'locales' => [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'pt' => 'Português',
        'zh' => '中文',
        'ja' => '日本語',
        'ru' => 'Русский',
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
import KinetixLanguageSwitcher from '@/components/kinetix/KinetixLanguageSwitcher.vue';
</script>

<template>
    <!-- Dropdown (default): icon only -->
    <KinetixLanguageSwitcher />

    <!-- Dropdown: show the active locale code beside the icon, e.g. "EN" -->
    <KinetixLanguageSwitcher show-label />

    <!-- Select: a labelled field for a settings form -->
    <KinetixLanguageSwitcher variant="select" />

    <!-- Select: your own label text -->
    <KinetixLanguageSwitcher variant="select" label="Idioma" />

    <!-- Select: no visible label (keeps an aria-label), e.g. in a tight toolbar -->
    <KinetixLanguageSwitcher variant="select" :show-label="false" />
</template>
```

### Props

| Prop | Type | Default | Description |
| --- | --- | --- | --- |
| `variant` | `'dropdown' \| 'select'` | `'dropdown'` | Which shape to render |
| `showLabel` | `boolean` | `false` for `dropdown`, `true` for `select` | Dropdown: show the active locale code beside the icon. Select: render the visible field label (an `aria-label` is used when off) |
| `label` | `string \| null` | `null` | **Select only.** Overrides the label text (defaults to the translated `kinetix.language`) |

Selecting a locale flips the SPA immediately and persists the choice, rolling
back if the request fails. The `select` variant's field downloads where it's
used; the default dropdown ships with the component. The locales come from the shared `kinetix_locale`
Inertia prop — no extra wiring needed.

### Both variants at once

The two variants share their state through vue-i18n's own locale, so a header
dropdown and a settings select stay in agreement without a page reload:

```vue
<AppHeader>
    <KinetixLanguageSwitcher show-label />
</AppHeader>

<SettingsForm>
    <!-- Switch here and the header updates immediately -->
    <KinetixLanguageSwitcher variant="select" />
</SettingsForm>
```

### A custom UI

`useKinetixLocale()` exposes `{ locales, current, saving, setLocale }` if neither
shape fits. `current` is derived from the active app language rather than held per
instance, so anything built on it stays in sync with the shipped variants:

```vue
<script setup lang="ts">
import { useKinetixLocale } from '@/composables/useKinetixLocale';

const { locales, current, saving, setLocale } = useKinetixLocale();
</script>

<template>
    <button
        v-for="loc in locales"
        :key="loc.code"
        :disabled="saving || loc.code === current"
        @click="setLocale(loc.code)"
    >
        {{ loc.label }}
    </button>
</template>
```

::: tip Compiling the new locale
The switcher only changes the active language — the matching translation messages
must already be loaded by your Vue i18n setup (or loadable, with
[lazy locales](#loading-locales-on-demand)). When you add a locale, re-run your
translation compile step (e.g. `php artisan vue-i18n:generate`) so the strings are
available. See [Installation](/installation).
:::

---

## Loading locales on demand

The default wiring imports every locale's messages into your entry bundle.
Kinetix's own strings are about 20 KB gzipped per locale, so a seven-locale app
ships about 140 KB that most visitors never use. Lazy locales load only the
active locale and the fallback before the first paint, and fetch the others when
the user switches.

It is **opt-in**: it needs per-locale message files
(`php artisan vue-i18n:generate --multi-locales`) and a few lines in **each**
entry file. `php artisan kinetix:install --lazy-locales` writes them into a
fresh `app.ts`. See
[Installation → Load each locale on demand](/installation#load-each-locale-on-demand)
for the setup steps.

### When it pays off

- Lazy mode always loads the active locale **and** the fallback before the first
  paint. It saves the most with three or more locales, or when most visitors
  use the fallback locale (then only one locale loads).
- With two locales and an app locale that differs from the fallback, both load
  anyway: the result is roughly neutral, or slightly worse because of the extra
  requests. Keep the eager wiring there, or use
  [`preloadFallback: 'idle'`](#deferring-the-fallback).
- Only the message trees move out of the entry. The vue-i18n runtime stays in it.
- **Every entry needs the wiring.** An entry left eager (an SSR `ssr.ts`, a
  guest-pages `public.ts`) keeps the full message set, and its static import of
  the per-locale files stops the bundler from emitting them as standalone
  chunks.

### How it works

`@/plugins/kinetixLocales` keeps one cache of loaded messages:

| Export | What it does |
| --- | --- |
| `registerKinetixLocales({ loader, fallbackLocale })` | Turns lazy mode on. Without it every export below is a no-op, so eager wiring keeps working unchanged. |
| `kinetixLocaleLoaderFromGlob(...maps)` | Builds a loader from one or more `import.meta.glob` maps, with or without `{ import: 'default' }`. Files are matched by basename (`es.ts`, `es.json`); `index.*` is ignored; a `{ es: {...} }` default export is unwrapped. |
| `withKinetixLocales(resolve, { preloadFallback })` | Wraps Inertia's `resolve`. The page component and the messages load in parallel, and the page is handed over only when both are in, so the first paint never shows raw `kinetix.*` keys. |
| `kinetixPageLocale(page)` | The page's locale: `props.locale`, then `kinetix_locale.current`, then `<html lang>`, then the fallback. |
| `kinetixLoadedMessages()` | A fresh object of every loaded locale's messages, for `createI18n({ messages })`. |
| `attachKinetixI18n(i18n)` | Registers locales that load later on your client app's instance. |
| `loadKinetixLocale(code)` / `ensureKinetixLocale(code)` | Load a locale (and the fallback) ahead of time; both resolve `false` instead of rejecting. |

The switcher waits too: `useKinetixLocale().setLocale('fr')` loads the French
messages first, then flips the language, persists it and reloads. While it
waits, `saving` is `true`. If the messages can't be loaded, `setLocale` throws,
and the language and server state stay as they were.

### Root JSON catalogs

Kinetix's own strings live in PHP translation files, so the generated
per-locale files always carry them. Your app's root `lang/{locale}.json`
catalogs are another matter:

- The single-file bundle (`vue-i18n:generate` without flags) includes them,
  converted for vue-i18n.
- `--multi-locales` leaves them out, so the default lazy wiring doesn't load
  them either. If your eager `locales/index.ts` merged them by hand, it merged
  them **raw**.

To keep a hand merge like that, add the catalogs as a first glob (the later
map wins on a shared key):

```ts
registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(
        import.meta.glob('../../lang/*.json'),
        import.meta.glob(['./locales/*.ts', '!./locales/index.ts']),
    ),
    fallbackLocale: 'en',
});
```

::: warning Raw Laravel strings
The glob loads the JSON exactly as written. The generator's conversion doesn't
run on it:

- `:name` placeholders stay literal (vue-i18n expects `{name}`);
- Laravel plural ranges such as `{0}` and `[2,*]` aren't stripped;
- `@` isn't escaped.

A string with `@`, `{` or `}` can fail vue-i18n's message compiler, which then
renders the key and logs an error. Only add the glob for catalogs written in
vue-i18n syntax, or keep those strings in PHP files the generator converts.
:::

### Regional codes and the fallback

- A regional code loads its own file when there is one, then the other separator
  (`pt-BR` and `pt_BR`), then the base language: `es-MX` uses `es.ts`. The
  messages are registered under both codes.
- The fallback locale always loads with the active one. If the active locale's
  file fails to download, the page mounts on the fallback messages, logs one
  console warning, and the next navigation or switch tries again.
- If the fallback fails as well, raw keys show until the next successful load.
  Keep the fallback's file small and always deployed.

### Deferring the fallback

By default (`preloadFallback: 'block'`) the fallback loads before the first
paint, so a key missing from the active locale shows its fallback text at once.
If the active locale is complete (Kinetix's own keys exist in every shipped
locale; check your app's), let only the active locale block and load the
fallback once the browser is idle:

```ts
createInertiaApp({
    resolve: withKinetixLocales(/* your resolve */, { preloadFallback: 'idle' }),
    // …
});
```

- If the active locale fails to load, the page still waits for the fallback.
- With SSR, the server always renders with the fallback loaded, while the
  client paints before it arrives. A key missing from the active locale then
  renders differently on each side and causes a **hydration mismatch**. Use
  `'idle'` with SSR only when the active locale has every key.
- The fallback reaches vue-i18n through `attachKinetixI18n(i18n)`, so keep that
  call in `withApp`.
- The server always blocks, so SSR output never depends on timing.

### Server-side rendering

The plugin never touches `window` or `document` at import, and the cache holds
only the message trees, which Kinetix never mutates. Create the i18n instance
**per request** inside `withApp`, never at module scope in your SSR entry:

```ts
// resources/js/ssr.ts
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createI18n } from 'vue-i18n';
import { kinetixLoadedMessages, kinetixLocaleLoaderFromGlob, kinetixPageLocale, registerKinetixLocales, withKinetixLocales } from '@/plugins/kinetixLocales';

registerKinetixLocales({
    loader: kinetixLocaleLoaderFromGlob(import.meta.glob(['./locales/*.ts', '!./locales/index.ts'])),
    fallbackLocale: 'en',
});

createInertiaApp({
    resolve: withKinetixLocales((name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob('./pages/**/*.vue'))),
    withApp(app, { page }) {
        // One instance per request, built from the shared cache.
        app.use(createI18n({
            legacy: false,
            locale: kinetixPageLocale(page),
            fallbackLocale: 'en',
            messages: kinetixLoadedMessages(),
        }));
    },
});
```

The client awaits the same messages before it mounts, so hydration matches
(with the default `preloadFallback: 'block'`).
`attachKinetixI18n()` is a no-op on the server. The trees are shared by every
request, so on the server don't call `mergeLocaleMessage()` on a loaded locale:
it writes into the shared tree. To add messages, build new objects instead:

```ts
const loaded = kinetixLoadedMessages();

createI18n({ /* … */ messages: { ...loaded, en: { ...loaded.en, ...extraEnglish } } });
```

### The runtime-only vue-i18n build

vue-i18n's runtime-only build drops the message compiler, but then it can't
render plain string messages: they must be precompiled at build time. The
generator's `.ts` files are plain strings, so keep the default full build with
them. Consider the runtime-only build only if
your own toolchain precompiles every message, Kinetix's included.

---

## Endpoints

Registered under your Kinetix prefix (team-aware when `kinetix.teams` is on). The
switch endpoint is **auth-optional** so it works before login:

| Method | Route             | Name                   |
| ------ | ----------------- | ---------------------- |
| `POST` | `{prefix}/locale` | `kinetix.locale.update` |
| `POST` | `_kinetix/locale` | `kinetix.locale.switch` (teams on: the same switch without the team segment) |

With teams on, the first one sits under `{current_team}` like every Kinetix
endpoint; pages with no team in reach — the login screen, public docs — call
the second.

Body: `{ "locale": "es" }`. Unsupported codes are rejected with `422`.

::: tip Why this route has no `auth`
It is the one Kinetix endpoint that is deliberately reachable by guests, so the
switcher works on the login screen and in a setup wizard. It is safe because it
only writes the session (and the authenticated user's own `locale` column), and
the value must be one of the locales you configured — anything else is a `422`.
`MiddlewareEnforcementTest` records it as an explicit exception so it can't be
mistaken for an oversight.
:::

---

## Programmatic control

```php
use Happones\Kinetix\Locale\KinetixLocale;

KinetixLocale::set('es');        // persist + apply for the current user
KinetixLocale::current();        // 'es'
KinetixLocale::options();        // [['code' => 'en', 'label' => 'English'], ...]
```

---

## Formatting region: `es` vs `es-MX`

Numbers, money and calendars format in the app locale. A bare language formats
as that language's default region, which is rarely the one your users live in:
plain `es` groups five-digit numbers but not four-digit ones — `$1199` next to
`$12.999` in the same column — and uses Spain's separators. Map each language to
the region it formats in:

```php
// config/kinetix.php
'locale' => [
    // …
    'regions' => [
        'es' => 'es-MX',   // $1,199.00 and $12,999.00
        'pt' => 'pt-BR',
    ],
],
```

The mapping drives `->money()`, numeric columns, table summaries, usage meters
and the locale Kinetix hands date/number pickers (`KinetixLocale::bcp47()`).
Translations still come from the language (`lang/es`). A column's own
`->locale()` or `->money(locale: …)` always wins.

## Translating labels you declare in PHP (schemas)

Switching the locale only changes what a **translatable** string resolves to. Kinetix
ships its *own* UI strings already localized (7 locales), but **every display string
YOU set on a Table, Form, Action, Infolist or Resource is your app's copy** — so it
must go through Laravel's [`__()`](https://laravel.com/docs/localization) helper. A
raw literal is never translated, no matter which locale is active.

```php
// ❌ hardcoded — always English
TextInput::make('email')->label('Email address');
$table->heading('Blog posts');

// ✅ translatable — resolves against your lang files at request time
TextInput::make('email')->label(__('posts.fields.email'))->placeholder(__('posts.placeholders.email'));
$table->heading(__('posts.table.heading'))->description(__('posts.table.description'));

EditAction::make()->label(__('common.edit'));

SelectFilter::make('status')->options([
    'draft'     => __('posts.status.draft'),      // option labels are copy too
    'published' => __('posts.status.published'),
]);

Section::make(__('posts.sections.meta'));         // form/infolist headings
TextEntry::make('title')->label(__('posts.fields.title'));
```

This applies to every display-string setter: `Table::heading()` / `description()`,
column `->label()`, form `->label()` / `->placeholder()` / `->helperText()`,
`Action::label()` / `modalHeading()` / `modalDescription()`, filter & select
**option labels**, `Section` / `Tab` headings, and infolist `Entry::label()`.

For a resource's sidebar entry, override `getNavigationLabel()` (you can't call
`__()` in the `$navigationLabel` property default):

```php
public static function getNavigationLabel(): string
{
    return __('posts.navigation');
}
```

Put the keys in your own `lang/{locale}/posts.php` files (one per supported locale) —
this is standard Laravel i18n; Kinetix simply renders whatever the string resolves to.

> **No `->label()` = no wrapping needed.** A column/field/entry declared as
> `TextColumn::make('published_at')` (no explicit label) is auto-humanized to
> "Published at". Only wrap the strings you actually type.
