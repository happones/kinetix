---
name: kinetix-resources
description: "Guides the creation and integration of CRUD Resources in Kinetix (Controllers, Routes, Pages, Views, Forms, Tables). Activates when building or modifying controller resource paths."
license: MIT
metadata:
  author: happones
---

# Kinetix Resources Integration

## When to Apply

Activate this skill when:
- Designing CRUD pages (Index, Create, Edit, Show) for database models.
- Building controllers that map models to Kinetix tables and forms.
- Designing standard Inertia.js views to display listings and forms.
- Scoping operations under tenant/team parameters (e.g. `{current_team}/posts`).
- Managing a parent record's related records (hasMany/belongsToMany) on its edit/show page via **Relation Managers**.

## Security & teams rules (REQUIRED)

- **Policy-if-exists on every endpoint.** Resource controllers must enforce the
  model policy per action (`viewAny`/`create`/`view`/`update`/`delete`/`restore`/`forceDelete`);
  `kinetix:make-resource` scaffolds an `authorizeAction()` helper for this.
  Without a policy every check is skipped — create it
  (`make:policy {Model}Policy --model={Model}`) and keep routes in the `auth` group.
- **One scoping point.** All reads and record resolution go through
  `{Resource}::getEloquentQuery()` (`->findOrFail($id)` — never implicit
  route-model binding, never an inline `where('team_id', …)` in the controller).
- **Writes go through the resource's save hook.**
  `{Model}::create({Resource}::mutateFormDataBeforeSave($form->getState(...), 'create'))`
  and `$record->update(...->mutateFormDataBeforeSave(..., 'edit', $record))` — the
  hook stamps `team_id` on create and strips it on edit. `team_id` never
  belongs in a form schema.
- **Team-safe redirects.** Use `{Resource}::getUrl('index')`, never
  `route('x.index')` — the bare route call throws under a `{current_team}` prefix.
- **Gate Create explicitly.** `CreateAction::make()->authorize('create', {Model}::class)`
  — it has no record, so it renders for everyone otherwise.
- **Register permissions.** Override `permissionFeature()` (return the slug) so the
  resource's CRUD abilities appear in the role matrix; sync with `kinetix:permissions:sync`.


## UI reuse (DRY — REQUIRED)

Never re-write a component's classes to imitate an existing Kinetix component:

- **Buttons**: use `<KinetixButton variant="…" size="…" :loading :disabled>` —
  it owns the shadcn recipe plus the pending contract (loading → disabled +
  spinner + aria-busy). Map an action's status color with
  `actionButtonVariant(color)`. Only when a component genuinely can't be used
  (a `<DropdownMenuTrigger>`, a link) compose classes with
  `buttonVariants({ variant, size })` from `useKinetixShadcnVariants` — never
  a hand-copied class string.
- **Modals**: build on `primitives/KinetixModal.vue` (the shadcn new-york-v4
  dialog shell: overlay/panel animation, header/footer stacks, close button,
  focus trap, Kinetix z-scale) — never a hand-rolled Teleport + overlay div.
- **Checkboxes/selects/inputs**: `<KinetixCheckbox>`, `<KinetixSelect>`, the
  form field components.

A duplicated class string is a bug: when the base component evolves, the copy
silently drifts.

## Documentation

For component details, reference:
- [Kinetix Tables Reference](https://happones.github.io/kinetix/tables)
- [Kinetix Forms Reference](https://happones.github.io/kinetix/forms)

## Localizing labels

Every human-facing string across the resource's `table()`/`form()`/`infolist()`
is **your app's copy** — wrap it in Laravel's `__()` so it's translatable
(`->label(__('posts.fields.title'))`, `->heading(__('posts.table.heading'))`). For
the sidebar entry, override `getNavigationLabel()` to return `__('posts.nav')`
(you can't call `__()` in the `$navigationLabel` property default). Attribute-derived
column/field labels need no wrapping. See the **kinetix-locale** skill.

---

## CRUD Architecture Pattern

A Kinetix Resource represents a complete administrative module. It integrates a **Kinetix Table** on the index listing page and a **Kinetix Form** on the create/edit views.

### 1. Standard Resource Controller

Encapsulate schema definitions in dedicated helper methods (`getTable()` and `getForm()`) to keep actions lightweight and clean.

```php
namespace App\Http\Controllers;

use App\Models\Article;
use App\Enums\ArticleStatus;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\ToggleColumn;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Forms\Components\Grid;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Actions\Action;
use Illuminate\Http\Request;

class ArticleController extends Controller
{
    /**
     * Define the Index listing table
     */
    protected function getTable(): Table
    {
        return Table::make(Article::query())
            ->heading('Articles')
            ->description('Manage your database publications.')
            ->striped()
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->badgeColor(fn ($state) => $state === 'published' ? 'success' : 'gray'),
                ToggleColumn::make('is_featured')->label('Featured'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon('edit')
                    ->url(fn ($record) => route('articles.edit', $record)),
            ]);
    }

    /**
     * Define the Create/Edit form
     */
    protected function getForm(Article $article): Form
    {
        return Form::make($article)
            ->schema([
                Grid::make(12)->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(200)
                        ->columnSpan(8),

                    Select::make('status')
                        ->options(ArticleStatus::class)
                        ->required()
                        ->columnSpan(4),
                ]),
            ]);
    }

    public function index()
    {
        return inertia('Articles/Index', [
            'articlesTable' => $this->getTable()->toArray(),
        ]);
    }

    public function create()
    {
        $form = $this->getForm(new Article())->fill();

        return inertia('Articles/Create', [
            'articleForm' => $form->toArray(),
        ]);
    }

    public function store(Request $request)
    {
        $form = $this->getForm(new Article());
        $form->validate($request->all());
        
        Article::create($form->getState($request->all()));

        return redirect()->route('articles.index');
    }

    public function edit(Article $article)
    {
        $form = $this->getForm($article)->fill($article);

        return inertia('Articles/Edit', [
            'articleForm' => $form->toArray(),
        ]);
    }

    public function update(Request $request, Article $article)
    {
        $form = $this->getForm($article);
        $form->validate($request->all());

        $article->update($form->getState($request->all()));

        return redirect()->route('articles.index');
    }
}
```

### 2. Frontend Index View (`resources/js/pages/Articles/Index.vue`)

Use `<KinetixTable>` to render the listing with built-in sorting, filtering, and inline column editing.

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KinetixTable from '@/components/kinetix/KinetixTable.vue';
import type { KinetixTableData } from '@/types/kinetix';

defineProps<{
    articlesTable: KinetixTableData;
}>();
</script>

<template>
    <div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-xl font-bold">Publications</h1>
            <button
                @click="router.get(route('articles.create'))"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold shadow"
            >
                New Article
            </button>
        </div>
        
        <KinetixTable :table="articlesTable" />
    </div>
</template>
```

### 3. Frontend Edit View (`resources/js/pages/Articles/Edit.vue`)

Bind the serialized form DTO directly onto `<KinetixForm>`.

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KinetixForm from '@/components/kinetix/KinetixForm.vue';

const props = defineProps<{
    articleForm: any;
}>();

const handleSubmit = (values: Record<string, any>) => {
    router.put(route('articles.update', { article: values.id }), values, {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="max-w-3xl mx-auto py-10 px-4 sm:px-6">
        <h2 class="text-lg font-bold mb-6">Modify Publication Details</h2>
        
        <KinetixForm :form="articleForm" @submit="handleSubmit" />
    </div>
</template>
```

---

## Relation Managers

To manage a parent's related records on its edit/show page, extend `RelationManager`. It scopes a Kinetix Table to `$parent->{relationship}()` and namespaces its query string (`posts_search`, `posts_page`, …) so multiple managers coexist.

```php
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
        ]);
    }
}

// Edit/show controller — pass the record so canViewForRecord() gating applies.
return inertia('Users/Edit', [
    'relations' => collect(UserResource::relationManagersFor('edit', $user))
        ->map(fn (string $m) => $m::make($user)->toData())
        ->values(),
]);
```

```vue
<!-- Auto-tabs when >1 manager (Filament-style, with getBadge() counts); plain section when 1. -->
<KinetixRelationManagers :managers="relations" />
```

Key rules: resolve the PARENT through `Resource::getEloquentQuery()->findOrFail($id)` (never implicit route-model binding — a team-prefixed URL would render another team's record; `kinetix:make-resource` scaffolds this). **Modal CRUD (Filament convention)**: declare `form()` (+ optional `infolist()`) on the MANAGER and flag actions `->modal('create'|'edit'|'view'|'delete')` — the manager wires parent-bound endpoints automatically (create goes THROUGH the relationship so the FK/pivot is stamped server-side; edit/delete resolve through it, foreign ids 404; parent `update` policy + child policy gate everything). Never add a parent select/FK field to the manager's form. `recordModals()` inside a manager THROWS — the modal convention above replaces it. `getBadge()` puts counts on the tab; `canViewForRecord(Model $parent, string $page)` is the record/user-aware gate; `$title` passes through `__()`. BelongsToMany: `AttachAction`/`DetachAction` (picker of unattached records searching `$recordTitleAttribute`; detach = pivot-only); **pivot columns**: declare `->withPivot('role')` on the relationship, then `TextColumn::make('pivot.role')` displays/sorts/searches (real Pivot model hydrated; custom `->as()` accessor supported). **Writing pivot data** (all paths only touch `withPivot()` columns): `AttachAction::make()->form([TextInput::make('role')])` collects pivot fields in the attach modal (validated server-side against the manager's own form; written to every attached record's pivot row; a non-pivot field throws at serialize); plain-named fields matching pivot columns in the manager's `form()` fill from and save to the pivot row (`updateExistingPivot` on edit, attach pivot data on create; pivot wins over a same-named related attribute — the Filament rule); editable `pivot.*` columns inline-edit the pivot row (undeclared ones throw); `TextEntry::make('pivot.role')` resolves in the view infolist. HasMany/MorphMany: `AssociateAction`/`DissociateAction` (picker of FK-NULL orphans; dissociate = null the FK). Wrong relation type for any of the four throws at serialize time. `protected static bool $readOnly = true` strips all actions. `protected static bool $isLazy = true` defers the manager: only the tab stub (title + badge — keep `getBadge()` cheap) serializes until its tab is the active `?relation=`; the frontend host loads it automatically with a skeleton (deliberately NOT eager for the first tab; with several lazy managers use the tabs host — the stacked layout can only load one `?relation=` at a time). For multiple standalone tables on one page (without a relation), call `Table::make($q)->queryPrefix('foo_')` directly. Full reference: [Relation Managers](https://happones.github.io/kinetix/relation-managers).

---

## Best Practices

- **Route Definitions**: Use standard resource routes:
  ```php
  Route::resource('articles', ArticleController::class);
  ```
- **Thin Actions**: Keep actions simple. Offload all column rendering and layout blueprints to `getTable()` and `getForm()` helpers.
- **Scope Relationships**: Eager load related records inside the base query (`Article::with('author')`) to prevent N+1 queries during TextColumn rendering.
- **Validate Confidentially**: Never save validation confirmations like password checks in your model updates. Use `$field->saved(false)` to exclude them.
