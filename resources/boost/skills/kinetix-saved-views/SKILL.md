---
name: kinetix-saved-views
description: "Per-user table presets: save search + filters + sort + visible columns as named views, set a default, switch between them. Enable with ->saveViews() on a Table. Activates when adding saved views / table presets."
license: MIT
metadata:
  author: happones
---

# Kinetix Saved Views Development

## When to Apply

Activate this skill when:
- Letting users save and switch between table presets (filters/sort/columns).
- Adding a "Views" dropdown to a Kinetix table toolbar.

## Documentation

For full details, reference `docs/saved-views.md` (published at https://happones.github.io/kinetix/saved-views).

## Installation & Configuration

```bash
php artisan vendor:publish --tag=kinetix-saved-views-migrations
php artisan migrate
```

```php
'saved_views' => ['enabled' => env('KINETIX_SAVED_VIEWS_ENABLED', false)],
```

Enable per-table with `->saveViews()`:

```php
Table::make(Product::query())
    ->columns([...])
    ->filters([...])
    ->saveViews();              // key defaults to the model class
// or ->saveViews('products.admin') for a custom/shared key
```

Views are per-user and team-scoped automatically when `kinetix.teams` is on.

---

## How it works

`saveViews()` sets `TableData.savedViewsKey`; `KinetixTable` then renders the
`KinetixSavedViews` dropdown in the toolbar. A view captures
`{ search, sort, direction, perPage, filters, columns }`; applying one restores
all of it (column visibility is client-only, the rest via the query string). The
default view loads automatically on first render. No extra wiring needed.

- **Backend**: `SavedViewManager` (`for`/`create`/`update`/`delete`/`makeDefault`),
  `SavedViewController` (team-aware `{prefix}/saved-views`, owner-only).
- **Frontend**: `useKinetixSavedViews(viewKey)` →
  `{ views, loading, load, create, update, remove, setDefault }`. i18n
  `saved_view*` (en/es/fr/pt).
