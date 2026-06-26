# Spotlight Command Palette

Kinetix Spotlight is a global **`Cmd/Ctrl+K`** palette that searches across your
models, navigation and quick actions. Model searches use
[`laravel/scout`](https://laravel.com/docs/scout) when the model is `Searchable`,
otherwise a capped `LIKE` query. Results are **authorization-aware** — sources
self-gate and per-record policies are honored, so the palette never surfaces a
record the user can't see.

---

## Configuration

```php
'spotlight' => [
    'enabled' => env('KINETIX_SPOTLIGHT_ENABLED', false),
    // auto = Scout for Searchable models, else 'database' (LIKE). Force with 'database'.
    'driver'  => env('KINETIX_SPOTLIGHT_DRIVER', 'auto'),
    // Max results per source.
    'limit'   => env('KINETIX_SPOTLIGHT_LIMIT', 5),
],
```

---

## 1. Registering sources

Register sources in a service provider. There are two kinds:

```php
use Happones\Kinetix\Spotlight\KinetixSpotlight;
use Happones\Kinetix\Spotlight\SpotlightLink;
use Happones\Kinetix\Spotlight\SpotlightResource;

KinetixSpotlight::register([
    // Searchable model
    SpotlightResource::make(\App\Models\Post::class)
        ->titleAttribute('title')
        ->subtitle('status')
        ->searchColumns(['title', 'body'])      // used by the LIKE fallback
        ->url(fn ($post) => route('posts.edit', $post))
        ->icon('file-text')
        ->authorize('posts.viewAny')             // source-level ability (optional)
        ->limit(5),

    // Navigation link / quick action
    SpotlightLink::make('Billing')
        ->url('/billing')
        ->icon('credit-card')
        ->keywords(['invoice', 'subscription']),

    SpotlightLink::make('Create post')
        ->event('open-create-post')              // dispatched on the client instead of navigating
        ->icon('plus'),
]);
```

---

## 2. Authorization (the important part)

Two layers, both enforced server-side:

- **Source-level** — `->authorize($ability)` hides an entire source unless the
  `Gate` allows it (e.g. only admins see the "Users" source).
- **Per-record** — when a model has a policy, each result is filtered through its
  `view` policy. To pre-filter efficiently (and add team scoping), pass a base
  query:

  ```php
  SpotlightResource::make(Post::class)
      ->query(fn () => Post::where('team_id', auth()->user()->currentTeam->id))
      ->searchColumns(['title']);
  ```

Empty queries never dump the database — resources return nothing until there's a
search term; only links/actions show.

---

## 3. The palette

Mount `<KinetixSpotlight>` once in your authenticated layout — it owns the
`Cmd/Ctrl+K` shortcut and the dialog:

```vue
<script setup lang="ts">
import KinetixSpotlight from '@/components/kinetix/KinetixSpotlight.vue'
</script>

<template>
  <KinetixSpotlight />
  <slot />
</template>
```

Built on Reka UI's `Dialog` + `Combobox` (focus trap, keyboard navigation and
selection for free). Selecting a result navigates to its `url`, or — for an
action — dispatches a `window` `CustomEvent` named by its `event` (listen for it
to open a modal, etc.). The search is debounced; use `useKinetixSpotlight()` for
a custom UI.

---

## 4. Driver

`auto` (default) routes a `Searchable` model's search through Scout (using your
configured engine), and falls back to a `LIKE` query over `searchColumns` for
non-Scout models. Force the `LIKE` path with `driver = 'database'`.

> With Scout, results come from the search index; the per-record `view` policy
> still filters them, but for team/tenant scoping prefer Scout's own
> `where()`/index constraints or the `->query()` base query.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `{prefix}/spotlight?q=…` | Grouped, authorization-filtered results |
