---
name: kinetix-spotlight
description: "Global Cmd+K command palette over models, navigation and actions, authorization-aware, with a laravel/scout bridge. Activates when registering spotlight sources, mounting the palette, or wiring search."
license: MIT
metadata:
  author: happones
---

# Kinetix Spotlight Development

## When to Apply

Activate this skill when:
- Registering spotlight sources via `KinetixSpotlight::register([...])`
  (`SpotlightResource` / `SpotlightLink`).
- Mounting `<KinetixSpotlight>` or wiring `useKinetixSpotlight`.
- Scoping/authorizing palette results, or using the Scout vs LIKE driver.

## Documentation

For full details, reference `docs/spotlight.md` (published at https://happones.github.io/kinetix/spotlight).

## Configuration

```php
'spotlight' => [
    'enabled' => env('KINETIX_SPOTLIGHT_ENABLED', false),
    'driver'  => env('KINETIX_SPOTLIGHT_DRIVER', 'auto'), // auto | database
    'limit'   => env('KINETIX_SPOTLIGHT_LIMIT', 5),
],
```

---

## Backend Usage

```php
use Happones\Kinetix\Spotlight\{KinetixSpotlight, SpotlightResource, SpotlightLink};

KinetixSpotlight::register([
    SpotlightResource::make(Post::class)
        ->titleAttribute('title')->subtitle('status')
        ->searchColumns(['title'])                 // LIKE fallback columns
        ->url(fn ($p) => route('posts.edit', $p))
        ->authorize('posts.viewAny')               // source-level gate (optional)
        ->query(fn () => Post::where('team_id', $teamId)), // optional scoping
    SpotlightLink::make('Billing')->url('/billing')->keywords(['invoice']),
    SpotlightLink::make('Create post')->event('open-create-post'), // client action
]);
```

- **Authorization-aware**: source-level `->authorize($ability)` hides a source;
  per-record `view` policy filters results when the model has a policy. Empty
  query → only links (no DB dump).
- **Driver**: `auto` uses Scout when the model uses `Laravel\Scout\Searchable`
  (detected via `trait_exists` + `class_uses_recursive`), else a capped LIKE over
  `searchColumns`. `database` forces LIKE.
- Endpoint: `GET {prefix}/spotlight?q=…` → `{ groups: [{ label, items }] }`
  (team-aware).

---

## Frontend Usage

```vue
<!-- once in the authenticated layout -->
<KinetixSpotlight />
```

Owns `Cmd/Ctrl+K`. Built on Reka `Dialog` + `Combobox` (keyboard nav + selection).
Selecting navigates to `url`, or dispatches a `window` `CustomEvent` named by the
item's `event`. Debounced. `useKinetixSpotlight().search(q)` for a custom UI.
