# Kinetix Relation Managers

A **Relation Manager** lists and manages the records related to a parent model — e.g. a user's posts on the user's edit page. It is a thin composition over [Kinetix Tables](tables.md): the table is scoped to the parent's relationship query, and its query-string state is namespaced so several managers (and the resource's own table) can coexist on one page without clashing.

---

## 1. Architecture

```mermaid
graph LR
    P[Parent record] --> R[RelationManager]
    R -->|parent->relationship()->getQuery| T[Table scoped to relation]
    R -->|queryPrefix relationship_| T
    T --> D[RelationManagerData = title + relationship + TableData]
    D --> V[KinetixRelationManager.vue → KinetixTable]
```

- The relation query comes from `$parent->{relationship}()->getQuery()`, so all the parent's foreign-key constraints are applied automatically.
- The table is given a `queryPrefix` of `"{relationship}_"`, so its params become `posts_search`, `posts_page`, etc. — never colliding with the main table or sibling managers.
- Record/toolbar **Actions** (edit, delete, create, attach) are ordinary Kinetix `Action`s pointing at routes you define scoped to the parent + related record.

---

## 2. Defining a Relation Manager

```php
namespace App\Kinetix\RelationManagers;

use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Actions\Action;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';
    protected static ?string $title = 'Blog posts'; // optional; defaults to "Posts"

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->date(),
            ])
            ->recordActions([
                Action::make('edit')->icon('edit')->url(fn ($post) => route('posts.edit', $post)),
                Action::make('delete')->icon('trash')->color('danger')
                    ->requiresConfirmation('Delete this post?')
                    ->inertiaVisit(fn ($post) => route('posts.destroy', $post), ['method' => 'delete']),
            ])
            ->toolbarActions([
                Action::make('create')->label('New post')->icon('plus')
                    ->url(fn () => route('posts.create')),
            ]);
    }
}
```

### API

| Member | Description |
|---|---|
| `protected static $relationship` | The relationship **method name** on the parent (required) |
| `protected static $title` | Heading (defaults to a humanized relationship name) |
| `table(Table $table): Table` | Configure columns/filters/actions (the table is pre-scoped + prefixed) |
| `::make(?Model $parent)` | Bind the parent record |
| `getRelationshipQuery(): Builder` | The parent-scoped Eloquent query |
| `toData()` / `toArray()` | Serialize to `RelationManagerData` |

---

## 3. Rendering

```php
// In the parent's edit/show controller
return inertia('Users/Edit', [
    'user' => $user,
    'relations' => [
        PostsRelationManager::make($user)->toArray(),
        // ...more managers
    ],
]);
```

```vue
<script setup lang="ts">
import KinetixRelationManager from '@/components/kinetix/KinetixRelationManager.vue';
import type { KinetixRelationManagerData } from '@/types';

defineProps<{ relations: KinetixRelationManagerData[] }>();
</script>

<template>
    <div class="space-y-8">
        <KinetixRelationManager
            v-for="relation in relations"
            :key="relation.relationship"
            :manager="relation"
        />
    </div>
</template>
```

`KinetixRelationManager.vue` renders the title and the embedded `KinetixTable`. Because each table carries its own `queryPrefix`, searching/sorting/paginating one manager leaves the others untouched, and unrelated query params on the page are preserved across reloads.

---

## 4. Resource integration

`Resource` exposes a `relationManagers()` hook listing the managers for a resource:

```php
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function relationManagers(): array
    {
        return [PostsRelationManager::class];
    }
}
```

```php
$relations = array_map(
    fn (string $manager) => $manager::make($user)->toArray(),
    UserResource::relationManagers(),
);
```

### Per-page visibility (edit vs. view)

By default a relation manager shows on **both** the edit and the view (show) page.
Restrict it with the `$visibleOn` property:

```php
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    // Only on the read-only view page, never on edit.
    protected static array $visibleOn = ['view'];
}
```

Then build each page's list with `relationManagersFor($page)` instead of the raw
`relationManagers()`, so each page only gets the managers meant for it:

```php
// edit controller → only managers visible on 'edit'
$relations = array_map(
    fn (string $manager) => $manager::make($user)->toArray(),
    UserResource::relationManagersFor('edit'),
);

// view/show controller → managers visible on 'view'
$relations = array_map(
    fn (string $manager) => $manager::make($user)->toArray(),
    UserResource::relationManagersFor('view'),
);
```

For per-record logic (Filament's `canViewForRecord`), override `isVisibleOn()` on
the manager:

```php
public static function isVisibleOn(string $page): bool
{
    return $page === 'view'; // or inspect the page + any context you pass in
}
```

---

## 5. Query-string namespacing (`Table::queryPrefix`)

Relation managers rely on the same `Table::queryPrefix('posts_')` mechanism you can use directly when placing **multiple tables on one page**. With a prefix, the table reads `posts_search`, `posts_sort`, `posts_direction`, `posts_perPage`, `posts_filters[…]`, and paginates with a `posts_page` page name; `KinetixTable.vue` sends those prefixed params and preserves any foreign params already in the URL. An empty prefix (the default) keeps the classic unprefixed behaviour.

---

## 6. Recipe: full CRUD inside a relation manager

A complete "Posts of this User" panel with **create** (toolbar), **edit/delete** (per row), all scoped to the parent. CRUD is wired through ordinary `Action`s pointing at nested routes.

**1. The relation manager** — table + actions (the query is auto-scoped to `$user->posts()`):

```php
namespace App\Kinetix\RelationManagers;

use App\Models\Post;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    public function table(Table $table): Table
    {
        // $this->parent is the User; the query is constrained to its posts.
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->toolbarActions([
                Action::make('create')->label('New post')->icon('plus')
                    ->url(fn () => route('users.posts.create', $this->parent)),
            ])
            ->recordActions([
                EditAction::make()->url(fn (Post $post) => route('users.posts.edit', [$this->parent, $post])),
                DeleteAction::make()->inertiaVisit(
                    fn (Post $post) => route('users.posts.destroy', [$this->parent, $post]),
                    ['method' => 'delete'],
                ),
            ]);
    }
}
```

**2. Nested routes** for the relation's CRUD:

```php
Route::prefix('users/{user}')->name('users.posts.')->group(function () {
    Route::get('posts/create', [UserPostController::class, 'create'])->name('create');
    Route::post('posts', [UserPostController::class, 'store'])->name('store');
    Route::get('posts/{post}/edit', [UserPostController::class, 'edit'])->name('edit');
    Route::put('posts/{post}', [UserPostController::class, 'update'])->name('update');
    Route::delete('posts/{post}', [UserPostController::class, 'destroy'])->name('destroy');
});
```

**3. Render it on the parent's edit page** (see §3) — the create/edit actions navigate to the nested form pages, and `delete` issues a scoped Inertia `DELETE`. After a mutation, redirect back to the parent edit page and the relation table refreshes.

> Route binding is respected: passing the models to `route(...)` uses each model's `getRouteKey()` (so slug/uuid keys work). Authorize per row with `EditAction::make()->authorize('update')`, etc. — unauthorized actions are dropped from the payload.
