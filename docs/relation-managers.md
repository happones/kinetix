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

- The relation query comes from `$parent->{relationship}()` — qualified and pivot-aliased for BelongsToMany (§10/§11), so all the parent's foreign-key constraints are applied automatically.
- The table is given a `queryPrefix` of `"{relationship}_"`, so its params become `posts_search`, `posts_page`, etc. — never colliding with the main table or sibling managers.
- Actions are **modal-first**: `->modal('create'|'edit'|'view'|'delete')` with the manager's own `form()`/`infolist()` (§6), plus auto-wired Attach/Detach (§8) and Associate/Dissociate (§9) — zero routes. Routed actions (`->url()`, `->inertiaVisit()`) remain an option for full pages.

---

## 2. Defining a Relation Manager

```php
namespace App\Kinetix\RelationManagers;

use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\CreateAction;
use Happones\Kinetix\Actions\DeleteAction;
use Happones\Kinetix\Actions\EditAction;
use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tables\Columns\TextColumn;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';
    protected static ?string $title = 'Blog posts'; // optional; defaults to "Posts"

    // The create/edit MODALS render this schema (§6) — no routes, no parent FK.
    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('title')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->date(),
            ])
            ->toolbarActions([
                CreateAction::make()->modal('create'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->modal('edit'),
                    DeleteAction::make()->modal('delete'),
                ]),
            ]);
    }
}
```

Prefer full pages for a relation? Ordinary routed `Action`s
(`->url(fn ($post) => route('posts.edit', $post))`) still compose freely with
the modal ones in the same table.

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
| `canViewForRecord(Model $parent, string $page): bool` | Record/user-aware gating — see §4 |
| `getBadge(): int\|string\|null` | Badge next to the title / on the tab (e.g. a count) — see §3 |
| `getBadgeColor(): ?string` | Accessor for `$badgeColor` |
| `isVisibleOn(string $page): bool` | Page-level visibility (`'edit'` \| `'view'`) — see §4 |
| `protected static $recordTitleAttribute` | Related-model attribute the attach/associate pickers label/search by. **Defaults to the primary key when unset — the picker then shows raw ids as labels, so always set it** — see §8/§9 |
| `protected static $readOnly` | `true` renders the table with NO record/toolbar/bulk/footer actions |
| `protected static $isLazy` | `true` defers the manager to its tab activation — only the tab stub serializes until then (see §12) |
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

`<KinetixRelationManagers>` is the host and picks the layout automatically:

- **one manager** → a plain section (heading + table);
- **several** → an automatic **tab per manager** (title + optional badge),
  rendering only the active one. Because each table carries its own
  `queryPrefix`, switching tabs never clobbers another manager's
  search/sort/page state — and it survives in the URL.

**The active tab is part of the URL** (`?relation=<relationship>`, written
with a client-side history replace — no server round-trip): table reloads
(search/sort/filter/pagination), modal saves (their `back()` redirect), and
shared/bookmarked links all land on the tab the user was on. An unknown
`?relation=` value falls back to the first tab.

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

For per-record / per-user logic, override
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

## 6. Modal CRUD (create / edit / view / delete)

Relation managers get full CRUD **in modals** with zero routes and zero
controllers: declare a `form()` (and optionally an `infolist()`) on the
MANAGER — and flag the table's actions with
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
  policy (`view`/`create`/`update`/`delete`) when it has one. The Create
  toolbar button is auto-gated with `->authorize('create', Related::class)`
  unless you configured your own rule.
- `->modal('delete')` needs NO `form()`/`infolist()` — only create/edit
  require the form and view the infolist.
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

## 8. BelongsToMany: attach & detach

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
  `syncWithoutDetaching`, validating ids against the related model. Give the
  action a `->form([...])` of pivot fields to collect pivot data while
  attaching — see §11.
- **Detach** confirms first and removes **pivot rows only** — the related
  records are never deleted. Row and bulk both work.
- **Security**: every request re-validates the signed descriptor (user-bound,
  expiring), loads the parent, and — when the parent model has a policy —
  requires `update` on the PARENT (attaching/detaching children is editing
  the parent). Non-`BelongsToMany` relations with these actions throw at
  serialize time instead of rendering dead buttons.
- **Read-only variant**: `protected static bool $readOnly = true;` strips all
  record/toolbar/bulk/footer actions from the rendered table, whatever
  `table()` configured.

## 9. HasMany / MorphMany: associate & dissociate

The `HasMany`/`MorphMany` counterpart of attach/detach — re-parenting by
foreign key:

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
  parent** (foreign key `NULL`), searchable on
  `$recordTitleAttribute`; associating stamps the FK (and morph type)
  server-side via the relationship.
- **Dissociate** confirms first and **nulls the foreign key** — the related
  records are never deleted. The lookup is relation-scoped, so another
  parent's record ids are ignored.
- Same security contract as attach/detach (§8): signed descriptor +
  parent `update` policy. On a `BelongsToMany` relation these actions throw
  at serialize time — use Attach/Detach there.

## 10. Full Table parity & permissions inheritance

The manager's table IS a Kinetix Table — search box, filters, column
visibility toggle, sorting (a clicked header **wins over any order the
relation itself carries**), pagination/per-page, bulk actions, KPI stat
cards, summaries, saved views (namespaced per manager, never shared with the
related model's own index), polling and striped rows all work, with every
query param namespaced by the relationship (`tags_search`, `tags_page`, `tags_cursor`, …).
A fix in Table automatically fixes every manager. Notes that matter:

- **BelongsToMany is join-safe**: the relation query selects the related
  model's columns qualified, so a pivot with its own `id`/timestamps can
  never clobber the record's (row actions always target the RELATED record),
  and search/sort are table-qualified against the join.
- **Inline cell edits and drag reorder are parent-bound**: the write
  descriptor carries the relation itself, so record resolution happens
  THROUGH the parent's relationship — another parent's ids are dropped, for
  every relation type including BelongsToMany.
- **Permissions are inherited, never re-declared**: the related model's own
  policy (the same one its resource uses) governs the manager. Edit/View/
  Delete check `update`/`view`/`delete` per record; the Create modal action
  is auto-gated with `create` on the related class (pass your own
  `->authorize(...)` to override); the parent-bound endpoints re-check both
  the PARENT's `update` policy and the child's ability server-side. Under
  team-scoped spatie permissions the endpoints run inside the team-aware
  middleware group, so team roles apply.
- **The active tab lives in `?relation=`** (§3) and each table's state in its
  prefixed params — both survive reloads, modal saves, and shared links.

### Relation-scoped export

`ExportAction` works in a manager's toolbar, footer and bulk actions, and is
automatically **scoped to the parent's relationship**:

```php
->toolbarActions([
    ExportAction::make()->exporter(TaskExporter::class),
])
```

- The manager wires its signed descriptor into the export-start URL; the
  endpoint validates it (user-bound, expiring, parent `view` policy) and the
  queued export **intersects** the exporter's own `query()` with the
  relation's keys — tenant scoping in `query()` still applies in full, and a
  bulk export's selected ids narrow further on top.
- The exporter's `$model` must be the relation's related model, and the
  action must be wired via `->exporter()` — anything else throws at
  serialize time.
- A parent deleted between queueing and running exports zero rows.

Declare the pivot columns on the relationship (`->withPivot('role')`) and
address them through the pivot accessor:

```php
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';   // belongsToMany(...)->withPivot('role')

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('pivot.role')->badge()->searchable()->sortable(),
        ]);
    }
}
```

- The manager selects the pivot columns aliased and hydrates a **real Pivot
  model** per record, so `pivot.role` resolves like any other dot column —
  formatting, badges and `formatStateUsing` all work, and row ids always stay
  the RELATED model's keys.
- **Sort and search qualify against the joined pivot table** — no ambiguous
  columns, no fake relation lookups.
- A custom accessor works too: `->as('membership')` → `membership.role`.

### Writing pivot data

All three write paths work, and all of them only ever touch
columns declared in `withPivot()`:

**Pivot fields in the attach modal** — give the `AttachAction` a form; the
validated state is written to the pivot row of every record the picker
attaches:

```php
->toolbarActions([
    AttachAction::make()->form([
        TextInput::make('role')->required(),
    ]),
])
```

A form field that is **not** a `withPivot()` column throws at serialize time
(the endpoint could never write it). The endpoint revalidates against the
manager's own form server-side — submitted pivot data is ignored entirely
when no form is declared.

**Pivot fields in the edit/create modal** — add plain-named fields matching
`withPivot()` columns to the manager's `form()`; they fill from the pivot row
on edit and are routed to it on save (`updateExistingPivot`), while the rest
of the state goes to the related model. On create, BelongsToMany passes them
as the attach's pivot data. When a pivot column and a related attribute share
a name, **the pivot wins**:

```php
public function form(Form $form): Form
{
    return $form->schema([
        TextInput::make('name')->required(), // related model
        TextInput::make('role'),             // withPivot('role') → pivot row
    ]);
}
```

The view modal resolves `pivot.*` infolist entries too
(`TextEntry::make('pivot.role')`).

**Inline pivot cells** — editable columns on the pivot accessor
(`TextInputColumn::make('pivot.role')`, `SelectColumn`, …) write through the
relation-bound cell-update endpoint straight to the pivot row; the related
model never sees the value. An editable pivot column outside `withPivot()`
throws at serialize time. Note pivot writes go through the query builder
(`updateExistingPivot`), so Eloquent model events don't fire for them.

## 12. Lazy managers

Opt a heavy manager out of the initial page render:

```php
class AuditLogRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static bool $isLazy = true;
}
```

- **Lazy is opt-in by design — eager is the default.** Loading a deferred
  manager costs a full Inertia page revisit (props re-serialize), which is a
  great trade for a genuinely heavy tab but pure overhead for the common
  small one: lazy-by-default would turn every page with managers into two
  requests plus a skeleton flash. Reserve `$isLazy` for managers whose
  queries you actually feel.
- A lazy manager serializes only its **tab stub** (title + badge) until it is
  the active `?relation=` — none of its table queries run for tabs nobody
  opens, on the initial render **or** on any later table interaction from a
  sibling tab.
- When its tab activates, the frontend revisits with
  `?relation={relationship}` automatically and shows a pulsing skeleton
  meanwhile; once the param is in the URL, everything (search, sort,
  pagination, modal saves) behaves exactly like an eager manager.
- `getBadge()` **still runs for the stub** so the tab can show its count —
  keep it cheap on lazy managers.
- Deliberately **not** "first tab loads eagerly": even a lazy manager whose
  tab starts active defers to a follow-up request — that's the point of
  lazy. If the first tab should render with the page, leave it eager.
- Serialize-time misconfiguration guards (export inside a manager, undeclared
  pivot columns…) fire when the manager **loads**, not on the stub.
- With the **stacked layout** (`tabs: false` or a hand-placed
  `<KinetixRelationManager>`), at most ONE lazy manager per page can load —
  the single `?relation=` param can only name one. Use the tabs host (the
  default) when several managers are lazy.

## 13. What's not supported (yet)

So you don't discover it the hard way:

- **`recordModals()` inside a relation manager** — rejected with an
  exception; you don't need it: declare `form()`/`infolist()` on the manager
  and flag actions with `->modal()` (§6) — the manager wires the
  parent-bound endpoints itself.
- **`ImportAction`** (toolbar/footer, **including inside an `ActionGroup`**)
  — rejected with an exception: imported rows would not be attached to the
  parent. Import from the related resource's own index instead.
  (`ExportAction` IS supported — see §10.)
- **Grouped/collapsible managers and custom empty states** — planned polish;
  the flat auto-tabs host covers the common case.

## 14. i18n

`protected static ?string $title` passes through `__()`, so a translation key
works out of the box:

```php
protected static ?string $title = 'app.relation_managers.posts';
```

The default (no title) is the headlined relationship name; the table chrome
(search, pagination, empty state…) is already translated in all 7 locales.
