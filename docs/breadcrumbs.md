# Breadcrumbs

The Laravel starter kit already ships a `<Breadcrumbs>` component — so Kinetix
doesn't replace it. Instead it **auto-derives the breadcrumb trail from your
Resource** on the server and shares it as a page prop, so you don't hand-write
the trail on every page. You feed that data straight into the starter kit's
existing breadcrumbs.

---

## Generated resources get them for free

`php artisan kinetix:make-resource` now emits a `breadcrumbs` prop from each
controller action:

```php
return inertia('Kinetix/Products/Index', [
    'table'       => ProductResource::table(Table::make($query))->toArray(),
    'breadcrumbs' => ProductResource::breadcrumbs('index'),
]);

return inertia('Kinetix/Products/Edit', [
    'form'        => $form->toArray(),
    'recordId'    => $record->getKey(),
    'breadcrumbs' => ProductResource::breadcrumbs('edit', $record),
]);
```

The generated Vue pages already declare the typed prop:

```ts
import type { KinetixBreadcrumb } from '@/types';

defineProps<{
    table: KinetixTableData;
    breadcrumbs?: KinetixBreadcrumb[];
}>();
```

---

## `Resource::breadcrumbs()`

```php
ProductResource::breadcrumbs('index');              // [Products]
ProductResource::breadcrumbs('create');             // [Products, Create]
ProductResource::breadcrumbs('show', $product);     // [Products, "Widget"]
ProductResource::breadcrumbs('edit', $product);     // [Products, "Widget", Edit]
```

Each item is `{ title, href }` — the same shape as the starter kit's
`BreadcrumbItem`. The trail is built from:

- **`getNavigationLabel()`** — the root crumb (e.g. *Products*), linking to the
  index route.
- **`getRecordTitle($record)`** — the record crumb. Defaults to the record's
  `name`, then `title`, then `#{id}`. Override with
  `protected static ?string $recordTitleAttribute = 'subject';`.
- **`getRouteBaseName()`** — used to build links (`{base}.index`, `{base}.show`).
  Defaults to the pluralized, kebab-cased model name (`products`), matching the
  routes the generator creates. Override with
  `protected static ?string $routeBaseName = 'shop.products';`.

Links are resolved with `route()`, auto-filling the record and a `current_team`
param when the route expects one. If a route can't be built it falls back to the
current URL, so breadcrumbs never throw.

The `Create` / `Edit` labels are translated (`kinetix.breadcrumb_create`,
`kinetix.breadcrumb_edit`; en/es/fr/pt).

---

## Feeding the starter kit's layout

The starter kit renders breadcrumbs from a layout prop. Pass the server-provided
trail to it:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { KinetixBreadcrumb } from '@/types';

const props = defineProps<{
    table: KinetixTableData;
    breadcrumbs?: KinetixBreadcrumb[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs ?? []">
        <KinetixTable :table="table" />
    </AppLayout>
</template>
```

Adjust `AppLayout` / the prop name to match your starter kit. Because the data is
already in the page props, wiring is a one-liner — and changing a resource's
label or route base updates every breadcrumb automatically.

---

## Custom (non-resource) pages

Call the builder from any controller, or build the array yourself — it's just
`{ title, href }[]`:

```php
return inertia('Reports/Sales', [
    'breadcrumbs' => [
        ['title' => 'Reports', 'href' => route('reports.index')],
        ['title' => 'Sales', 'href' => url()->current()],
    ],
]);
```
