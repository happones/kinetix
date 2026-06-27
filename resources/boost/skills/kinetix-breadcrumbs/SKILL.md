---
name: kinetix-breadcrumbs
description: "Auto-derive breadcrumbs from a Kinetix Resource and feed the starter kit's <Breadcrumbs>. Resource::breadcrumbs() returns {title,href}[] for index/create/edit/show. Activates when adding breadcrumbs to resource pages."
license: MIT
metadata:
  author: happones
---

# Kinetix Resource Breadcrumbs

## When to Apply

Activate this skill when:
- Adding breadcrumbs to a Kinetix Resource page (index/create/edit/show).
- Wanting a server-derived breadcrumb trail instead of hand-writing it per page.

Kinetix does **not** ship a breadcrumbs component — the Laravel starter kit
already has one. Kinetix derives the *trail* from the Resource; you feed it to the
existing `<Breadcrumbs>`.

## Documentation

For full details, reference `docs/breadcrumbs.md` (published at https://happones.github.io/kinetix/breadcrumbs).

## Builder

```php
ProductResource::breadcrumbs('index');           // [Products]
ProductResource::breadcrumbs('create');          // [Products, Create]
ProductResource::breadcrumbs('show', $product);  // [Products, "Widget"]
ProductResource::breadcrumbs('edit', $product);  // [Products, "Widget", Edit]
```

Each item is `{ title, href }`. Built from `getNavigationLabel()` (root → index
route), `getRecordTitle($record)` (`name`→`title`→`#id`; override
`$recordTitleAttribute`) and `getRouteBaseName()` (plural-kebab model name;
override `$routeBaseName`). Links resolve with `route()` (auto-filling the record
+ `current_team`); falls back to the current URL so it never throws.

## In a controller

```php
return inertia('Kinetix/Products/Edit', [
    'form'        => $form->toArray(),
    'breadcrumbs' => ProductResource::breadcrumbs('edit', $record),
]);
```

`kinetix:make-resource` emits this automatically. Generated pages declare
`breadcrumbs?: KinetixBreadcrumb[]`.

## In the page

```vue
<AppLayout :breadcrumbs="breadcrumbs ?? []">…</AppLayout>
```

Adjust the layout/prop to your starter kit. i18n `breadcrumb_create/edit`.
