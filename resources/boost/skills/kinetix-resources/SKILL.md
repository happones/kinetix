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

## Documentation

For component details, reference:
- [Kinetix Tables Reference](file:///home/happones/Plugins/Php/kinetix/docs/tables.md)
- [Kinetix Forms Reference](file:///home/happones/Plugins/Php/kinetix/docs/forms.md)

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
import type { KinetixTableData } from '@/types';

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

// Edit/show controller
return inertia('Users/Edit', [
    'relations' => [PostsRelationManager::make($user)->toArray()],
]);
```

```vue
<KinetixRelationManager v-for="r in relations" :key="r.relationship" :manager="r" />
```

For multiple standalone tables on one page (without a relation), call `Table::make($q)->queryPrefix('foo_')` directly. Full reference: [Relation Managers](file:///home/happones/Plugins/Php/kinetix/docs/relation-managers.md).

---

## Best Practices

- **Route Definitions**: Use standard resource routes:
  ```php
  Route::resource('articles', ArticleController::class);
  ```
- **Thin Actions**: Keep actions simple. Offload all column rendering and layout blueprints to `getTable()` and `getForm()` helpers.
- **Scope Relationships**: Eager load related records inside the base query (`Article::with('author')`) to prevent N+1 queries during TextColumn rendering.
- **Validate Confidentially**: Never save validation confirmations like password checks in your model updates. Use `$field->saved(false)` to exclude them.
