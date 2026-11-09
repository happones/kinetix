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

The generated Vue pages declare the typed prop and hand it to the app layout
([below](#feeding-the-starter-kit-s-layout)):

```ts
import type { KinetixBreadcrumb } from '@/types/kinetix';

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

The starter kit picks the layout for every page in `createInertiaApp({ layout })`
(`AppLayout` by default) and reads its breadcrumbs from **layout props**. A
page hands them over with `defineOptions({ layout })`, a function of the page's
props, which is exactly what the generated pages do:

```vue
<script setup lang="ts">
import type { KinetixBreadcrumb, KinetixTableData } from '@/types/kinetix';

defineProps<{
    table: KinetixTableData;
    breadcrumbs?: KinetixBreadcrumb[];
}>();

defineOptions({
    layout: (props: { breadcrumbs?: KinetixBreadcrumb[] }) => ({
        breadcrumbs: props.breadcrumbs ?? [],
    }),
});
</script>
```

Don't wrap the page in `<AppLayout>` as well: the default layout already wraps
it, so the sidebar would render twice. Because the trail comes from the page
props, changing a resource's label or route base updates every breadcrumb, and
a team-scoped URL keeps its `{current_team}` segment.

> The layout also receives every page prop on its own, so a page that only
> declares the `breadcrumbs` prop (pages generated before 0.281.0) already shows
> the trail. The `defineOptions` block makes the hand-off explicit and lets you
> rename or reshape it for your layout.

If your layout takes a different prop, rename the key in the returned object.
An app that still renders its layout inside each page can pass the prop
directly: `<AppLayout :breadcrumbs="breadcrumbs ?? []">`.

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
