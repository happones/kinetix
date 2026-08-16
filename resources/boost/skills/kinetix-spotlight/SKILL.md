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
    'enabled'   => env('KINETIX_SPOTLIGHT_ENABLED', false),
    'driver'    => env('KINETIX_SPOTLIGHT_DRIVER', 'auto'), // auto | database
    'limit'     => env('KINETIX_SPOTLIGHT_LIMIT', 5),       // per source; ->limit() overrides
    'min_chars' => env('KINETIX_SPOTLIGHT_MIN_CHARS', 2),   // shortest query that hits the DB
    'throttle'  => env('KINETIX_SPOTLIGHT_THROTTLE', '60,1'), // null removes the rate limit
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
        ->query(fn () => Post::where('team_id', $teamId)) // tenancy seam — scopes BOTH drivers
        ->scoutWhere(['team_id' => $teamId])              // engine-side too, when indexed
        ->trustQuery()                                    // query is authz-complete → skip the policy pass
        ->priority(10),                                   // higher = group sorts first
    SpotlightLink::make('Billing')->url('/billing')->keywords(['invoice']),
    SpotlightLink::make('Create post')->event('open-create-post'), // client action
]);
```

- **Authorization-aware**: source-level `->authorize($ability)` hides a source;
  per-record `view` policy filters results when the model has a policy. Kinetix
  pages candidates (up to 5 x limit) until the limit is filled, so a rejecting
  policy under-fills only when the result set really is exhausted. `trustQuery()`
  skips the pass when `query()` already answers it.
- **`query()` scopes BOTH drivers (tenancy).** Under Scout the engine only
  proposes candidates; they are hydrated through `query()`, so a row it excludes
  can never surface. The engine still spends its buffer first, so pair it with
  `scoutWhere()` on an indexed attribute or the reader sees fewer results than
  the limit. NEVER rely on the per-record policy alone for tenant isolation.
- **`min_chars` (default 2)** is enforced in `SpotlightResource` (the expensive
  sources) and in the palette, which reads it from `kinetix_config.spotlight`.
  `SpotlightLink` still answers short/empty queries — it is in-memory.
- **The endpoint is throttled** (`spotlight.throttle`, default `60,1`): one
  request fans out to every authorized source.
- **Group order** = source `->priority()` desc, then registration order. Only
  the database driver re-ranks within a group (prefix matches first); Scout
  results keep the engine's relevance order.
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
item's `event`. Debounced (200ms).

`useKinetixSpotlight()` -> `{ loading, minChars, search }`. **`search(q)` resolves
to `null` when a newer search superseded it** (each call aborts the one before
it via `AbortSignal`), so callers must skip the assignment rather than paint it:

```ts
const result = await search(q);
if (result !== null) { groups.value = result; }
```

`kinetixFetch` accepts `signal?: AbortSignal`; `isKinetixAbort(error)` tells an
aborted request apart from a real failure.
