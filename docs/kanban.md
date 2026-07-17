# Kanban

Kinetix Kanban turns any Eloquent model into a **drag-and-drop board**: records
are grouped into columns by a status attribute, and dragging a card to another
column persists the new status. It's a server-driven builder like
[Tables](/tables) — the move is guarded by a signed descriptor, so only the
status column and the declared statuses can be written.

<Screenshot name="kanban" alt="Kanban board" />

::: tip Dense columns virtualize automatically
When a column holds more than ~40 cards, that column windows its card list via
`@tanstack/vue-virtual` (only visible cards render); the column stays the drop
target, so drag-and-drop is unaffected. Smaller columns render in full. Install
the optional peer if your boards get dense: `npm install @tanstack/vue-virtual`
(or `php artisan kinetix:install --tanstack`).
:::

---

## Defining a board

```php
use Happones\Kinetix\Kanban\Kanban;

$board = Kanban::make(Task::query())
    ->statusColumn('status')
    ->statuses([
        'todo'  => 'To Do',
        'doing' => ['label' => 'In Progress', 'color' => '#3b82f6'],
        'done'  => ['label' => 'Done', 'color' => '#22c55e'],
    ])
    ->cardTitle('title')
    ->cardDescription(fn (Task $t) => $t->assignee?->name)
    ->query(fn ($q) => $q->where('archived', false))   // optional base scope
    ->heading('Tasks');

return Inertia::render('Tasks/Board', ['board' => $board->toData()]);
```

- **`statusColumn`** — the DB column holding the status (default `status`).
- **`statuses`** — column key → label, or `['label' => …, 'color' => …]`.
- **`cardTitle`** / **`cardDescription`** — an attribute name or a closure.
- **`query`** — modify the base query (filters, eager loads).
- **`moveScope`** / **`authorizeMove`** — guard who can move which records (see below).

### Enum status columns

A `statusColumn` cast to a PHP enum works out of the box — grouping stringifies
the cast value (`BackedEnum` → its backing value, `UnitEnum` → the case name),
and a move assigns the plain status string back, which Eloquent re-casts:

```php
enum DealPhase: string
{
    case Lead = 'lead';
    case Won  = 'won';
}

// Deal: protected $casts = ['phase' => DealPhase::class];

Kanban::make(Deal::query())
    ->statusColumn('phase')
    ->statuses(['lead' => 'Lead', 'won' => 'Won']);   // keys = backing values
```

---

## Rendering

```vue
<script setup lang="ts">
import KinetixKanban from '@/components/KinetixKanban.vue';

defineProps<{ board: object }>();
</script>

<template>
    <KinetixKanban :kanban="board" />
</template>
```

Cards are draggable (native HTML5 drag-and-drop — no extra dependency). Dropping
a card into another column moves it optimistically and `POST`s the change; if the
request fails the card snaps back and a toast is shown. The board reloads after a
successful move so server-side ordering/derived data stays in sync.

---

## How the move is secured

`toData()` bakes a **signed descriptor** (`Crypt::encrypt`) of the model, status
column, allowed statuses, move scope and move ability into the payload — the
same mechanism as editable table cells. The `kanban-move` endpoint decrypts it
and only writes the declared status column to one of the declared statuses, so a
client can't tamper with the target column or push an arbitrary value.

| Method | Route                              | Name                          |
| ------ | ---------------------------------- | ----------------------------- |
| `POST` | `{prefix}/tables/kanban-move`      | `kinetix.tables.kanban-move`  |

The endpoint takes `{ model, recordId, status }` and is always available (guarded
by the descriptor); no migration or config flag is needed.

### Record-level authorization (multi-tenant)

The descriptor proves the *board* is legitimate — it does not by itself prove
the *record* belongs to the requesting user. Two layers close that (use at
least one in a multi-tenant app):

**1. Policy check (automatic).** When the model has a registered policy, every
move is authorized against it — by default the `update` ability, or whichever
ability you name:

```php
Kanban::make(Deal::query())->authorizeMove('moveCard');   // checks DealPolicy::moveCard($user, $deal)
```

A denied policy returns `403` and the card snaps back. With no policy
registered, no ability is checked (matching the rest of Kinetix's
opt-in-enforcement modules).

**2. Move scope (baked constraints).** `moveScope()` takes `column => value`
constraints evaluated **now** (in the request, where the tenant is known),
sealed into the encrypted descriptor and enforced on the endpoint's record
lookup — a record outside them is a `404`, so users can't move other tenants'
records by guessing ids:

```php
Kanban::make(Deal::query())
    ->query(fn ($q) => $q->where('team_id', $teamId))
    ->moveScope(['team_id' => $teamId]);   // same constraint, enforced on move
```
