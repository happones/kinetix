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
| `protected static $title` | Heading — passed through `__()`, so a translation key works (defaults to a humanized relationship name) |
| `protected static $visibleOn` | Pages the manager appears on (`['edit', 'view']` by default) |
| `protected static $badgeColor` | Status color for the badge (primary, gray, success…) |
| `table(Table $table): Table` | Configure columns/filters/actions (the table is pre-scoped + prefixed) |
| `form(Form $form): Form` | Schema for the create/edit **modals** — enables `->modal('create'\|'edit')` actions; never needs a parent FK field (see §6) |
| `infolist(Infolist $infolist): Infolist` | Read-only detail for the View **modal** — enables `->modal('view')` (see §6) |
| `::make(?Model $parent)` | Bind the parent record |
| `canViewForRecord(Model $parent, string $page): bool` | Record/user-aware gating (Filament analogue) — see §4 |
| `getBadge(): int\|string\|null` | Badge next to the title / on the tab (e.g. a count) — see §3 |
| `protected static $recordTitleAttribute` | Related-model attribute the attach/associate pickers label/search by — see §7.5/§7.6 |
| `protected static $readOnly` | `true` renders the table with NO record/toolbar/bulk actions |
| `getRelation(): Relation` | The parent's relationship OBJECT (BelongsToMany keeps its pivot) |
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
import KinetixRelationManagers from '@/components/kinetix/KinetixRelationManagers.vue';
import type { KinetixRelationManagerData } from '@/types/kinetix';

defineProps<{ relations: KinetixRelationManagerData[] }>();
</script>

<template>
    <KinetixRelationManagers :managers="relations" />
</template>
```

`<KinetixRelationManagers>` is the host and picks the layout automatically,
exactly like Filament:

- **one manager** → a plain section (heading + table);
- **several** → an automatic **tab per manager** (title + optional badge),
  rendering only the active one. Because each table carries its own
  `queryPrefix`, switching tabs never clobbers another manager's
  search/sort/page state — and it survives in the URL.

Pass `:tabs="false"` to force the stacked layout regardless of count, or use
the single-section `<KinetixRelationManager :manager="relation" />` directly
for a fully custom arrangement.

### Badges (record counts on the tab)

Override `getBadge()` — the value renders next to the title and on the tab
(pair it with `protected static ?string $badgeColor = 'primary';`):

```php
public function getBadge(): int|string|null
{
    return $this->getRelationshipQuery()->count();
}
```

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

For per-record / per-user logic (Filament's `canViewForRecord`), override
`canViewForRecord()` — it receives the PARENT record, and
`relationManagersFor($page, $record)` filters through it whenever you pass the
record:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

public static function canViewForRecord(Model $parent, string $page): bool
{
    return parent::canViewForRecord($parent, $page)
        && Gate::allows('viewComments', $parent);
}
```

```php
// Controller: pass the record so record/user-aware gating applies.
'relations' => collect(UserResource::relationManagersFor('view', $user))
    ->map(fn (string $manager) => $manager::make($user)->toData())
    ->values(),
```

(`isVisibleOn()` remains the page-level filter and the fallback when no record
is passed.)

---

## 5. Query-string namespacing (`Table::queryPrefix`)

Relation managers rely on the same `Table::queryPrefix('posts_')` mechanism you can use directly when placing **multiple tables on one page**. With a prefix, the table reads `posts_search`, `posts_sort`, `posts_direction`, `posts_perPage`, `posts_filters[…]`, and paginates with a `posts_page` page name; `KinetixTable.vue` sends those prefixed params and preserves any foreign params already in the URL. An empty prefix (the default) keeps the classic unprefixed behaviour.

---

## 6. Modal CRUD (create / edit / view / delete) — the Filament convention

Relation managers get full CRUD **in modals** with zero routes and zero
controllers: declare a `form()` (and optionally an `infolist()`) on the
MANAGER — exactly Filament's convention — and flag the table's actions with
`->modal(...)`. The manager wires everything else automatically.

```php
namespace App\Kinetix\RelationManagers;

use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Actions\ViewAction;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Components\TextEntry;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    /**
     * The form the create/edit modals render. NOTE: no `user_id` field —
     * created records are bound to the parent server-side (through the
     * relationship), so a parent select / FK field is never needed and a
     * forged one is ignored.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
            Select::make('status')->options([
                'draft' => 'Draft', 'published' => 'Published',
            ]),
        ]);
    }

    /** Read-only detail for the View modal (optional). */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('title'),
            TextEntry::make('status')->badge(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->toolbarActions([
                CreateAction::make()->modal('create'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->modal('view'),
                    EditAction::make()->modal('edit'),
                    DeleteAction::make()->modal('delete'),
                ]),
            ]);
    }
}
```

That's the whole implementation — render the manager on the parent page as in
§3 and the modals just work, at any scale, on any project.

**How it stays safe (the contract):**

- Every request carries the manager's **signed descriptor** (parent model +
  key + relationship + manager class, bound to the user it was minted for,
  expiring per `kinetix.tables.token_ttl`). The client never names a class.
- **Create goes THROUGH the relationship** — `HasMany`/`MorphMany` stamp the
  foreign key (and morph type); `BelongsToMany` creates the record **and**
  attaches it. A submitted FK is ignored.
- **Edit/View/Delete resolve THROUGH the relationship** — another parent's
  record id 404s, exactly like the table itself. Deleting a `BelongsToMany`
  record also drops its pivot row.
- **Authorization**: the PARENT's `update` policy gates every endpoint
  (touching children is editing the parent), plus the CHILD model's own
  policy (`view`/`create`/`update`/`delete`) when it has one.
- Works for `HasMany`, `MorphMany`, and `BelongsToMany`. Misconfiguration
  fails loudly at serialize time: `->modal('create')` without `form()` (or
  `->modal('view')` without `infolist()`) throws.

> Prefer full pages over modals for a relation? Wire ordinary `Action`s at
> your own nested routes (`->url(...)` / `->inertiaVisit(...)`) — modals and
> routed actions compose freely in the same table. Authorize per row with
> `EditAction::make()->authorize('update')`, etc.

---

## 7. Teams / multi-tenancy

Relation managers scope **transitively**: the table query is
`$parent->{relationship}()`, so the children are exactly as isolated as the
parent record you resolved. That makes parent resolution the whole ballgame:

::: danger Resolve the parent through the resource's scoped query
Implicit route-model binding (`public function edit(Post $record)`) fetches by
id alone — a team-prefixed URL like `/team-a/posts/{id-from-team-b}/edit`
would happily render team B's record, and every relation manager on that page
would then list team B's children. Resolve through the resource instead, so
out-of-scope ids 404:

```php
public function edit(string $record)
{
    $record = PostResource::getEloquentQuery()->findOrFail($record);
    // …
}
```

`kinetix:make-resource` scaffolds exactly this (and the same for
show/update/destroy/restore/forceDelete).
:::

Two more rules for team apps:

- **Nested CRUD routes must re-scope the parent too** — apply the same
  `getEloquentQuery()` resolution (or `->scopeBindings()` + an ownership
  check) in the nested controllers from §6.
- **Stamp the team on created children** when the child table has its own
  `team_id` (creating through `$parent->posts()->create(...)` inherits the
  parent FK but NOT other tenant columns).

## 7.5 BelongsToMany: attach & detach

For a `BelongsToMany` manager, drop in `AttachAction` / `DetachAction` — the
manager wires them to its own **signed descriptor** (parent + relation, bound
to the current user, expiring) automatically:

```php
use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\DetachAction;

class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    // The related-model attribute the attach modal labels + searches by.
    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->toolbarActions([AttachAction::make()])
            ->recordActions([DetachAction::make()]);   // also works in bulkActions()
    }
}
```

- **Attach** opens a modal listing the related records **not yet attached**
  (searchable on `$recordTitleAttribute`, capped at 50); attaching uses
  `syncWithoutDetaching`, validating ids against the related model.
- **Detach** confirms first and removes **pivot rows only** — the related
  records are never deleted. Row and bulk both work.
- **Security**: every request re-validates the signed descriptor (user-bound,
  expiring), loads the parent, and — when the parent model has a policy —
  requires `update` on the PARENT (attaching/detaching children is editing
  the parent). Non-`BelongsToMany` relations with these actions throw at
  serialize time instead of rendering dead buttons.
- **Read-only variant**: `protected static bool $readOnly = true;` strips all
  record/toolbar/bulk actions from the rendered table, whatever `table()`
  configured.

## 7.6 HasMany / MorphMany: associate & dissociate

The `HasMany`/`MorphMany` counterpart of attach/detach — re-parenting by
foreign key (Filament's Associate/Dissociate):

```php
use Happones\Kinetix\Actions\AssociateAction;
use Happones\Kinetix\Actions\DissociateAction;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $recordTitleAttribute = 'title';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->toolbarActions([AssociateAction::make()])
            ->recordActions([DissociateAction::make()]);   // also works in bulkActions()
    }
}
```

- **Associate** opens a modal listing the related records **not owned by any
  parent** (foreign key `NULL` — Filament's default scope), searchable on
  `$recordTitleAttribute`; associating stamps the FK (and morph type)
  server-side via the relationship.
- **Dissociate** confirms first and **nulls the foreign key** — the related
  records are never deleted. The lookup is relation-scoped, so another
  parent's record ids are ignored.
- Same security contract as attach/detach (§7.5): signed descriptor +
  parent `update` policy. On a `BelongsToMany` relation these actions throw
  at serialize time — use Attach/Detach there.

## 8. What's not supported (yet)

So you don't discover it the hard way:

- **`recordModals()` inside a relation manager** — rejected with an
  exception; you don't need it: declare `form()`/`infolist()` on the manager
  and flag actions with `->modal()` (§6) — the manager wires the
  parent-bound endpoints itself.
- **Pivot columns** (showing/editing pivot data in the table) — planned;
  `getRelation()` already exposes the relationship object with its pivot.

## 9. i18n

`protected static ?string $title` passes through `__()`, so a translation key
works out of the box:

```php
protected static ?string $title = 'app.relation_managers.posts';
```

The default (no title) is the headlined relationship name; the table chrome
(search, pagination, empty state…) is already translated in all 7 locales.
